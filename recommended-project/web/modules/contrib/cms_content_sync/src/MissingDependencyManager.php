<?php

namespace Drupal\cms_content_sync;

use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use EdgeBox\SyncCore\Exception\SyncCoreException;

/**
 * Class MissingDependencyManagement.
 *
 * Manage dependencies that couldn't be resolved. So if Content A references Content B and Content A is pulled before
 * Content B, then the reference can't be resolved. This class ensures that as soon as Content B becomes available,
 * Content A is updated as well.
 *
 * This can have multiple causes:
 * - If your Flow is configured to push ALL of a specific entity type, that push is not ordered. So for taxonomies
 *   for example the child term may be pulled before the parent term.
 * - If you don't use the "Push referenced entities automatically" functionality (e.g. with content that references
 *   other content), that content will also not arrive at the destination site in the required order (if ever).
 */
class MissingDependencyManager
{
    /**
     * @var string COLLECTION_NAME
     *             The KeyValue store to use for saving unresolved dependencies
     */
    public const COLLECTION_NAME = 'cms_content_sync_dependency';

    public const INDEX_ENTITY_TYPE = 'entity_type';
    public const INDEX_ENTITY_ID = 'id';
    public const INDEX_PULL_REASON = 'reason';
    public const INDEX_SET_FIELD = 'field';
    public const INDEX_DATA = 'data';

    /**
     * Save that an entity dependency could not be resolved so it triggers its pull automatically whenever it can be
     * resolved.
     *
     * @param string                              $referenced_entity_type
     * @param string                              $referenced_entity_shared_id
     * @param \Drupal\Core\Entity\EntityInterface $entity
     * @param string                              $reason
     * @param null|string                         $field
     * @param null|array                          $custom_data
     */
    public static function saveUnresolvedDependency($referenced_entity_type, $referenced_entity_shared_id, $entity, $reason, $field = null, $custom_data = null)
    {
        $storage = \Drupal::keyValue(self::COLLECTION_NAME);

        $id = $referenced_entity_type.':'.$referenced_entity_shared_id;

        $missing = $storage->get($id);
        if (empty($missing)) {
            $missing = [];
        }

        // Skip if that entity has already been added (referencing the same entity multiple times)
        foreach ($missing as $sync) {
            if ($sync[self::INDEX_ENTITY_TYPE] === $entity->getEntityTypeId() && $sync[self::INDEX_ENTITY_ID] === $entity->uuid() && (isset($sync[self::INDEX_SET_FIELD]) ? $sync[self::INDEX_SET_FIELD] : null) === $field) {
                return;
            }
        }

        $data = [
            self::INDEX_ENTITY_TYPE => $entity->getEntityTypeId(),
            self::INDEX_ENTITY_ID => $entity->uuid(),
            self::INDEX_PULL_REASON => $reason,
        ];

        if ($field) {
            $data[self::INDEX_SET_FIELD] = $field;
        }

        if ($custom_data) {
            $data[self::INDEX_DATA] = $custom_data;
        }

        $missing[] = $data;

        $storage->set($id, $missing);
    }

    /**
     * Resolve any dependencies that were missing before for the given entity that is now available.
     *
     * @param \Drupal\Core\Entity\EntityInterface $entity
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\Core\Entity\EntityStorageException
     */
    public static function resolveDependencies($entity)
    {
        $storage = \Drupal::keyValue(self::COLLECTION_NAME);

        if ('file' === $entity->getEntityTypeId()) {
            /**
             * @var \Drupal\file\Entity\File $entity
             */
            $id = $entity->getEntityTypeId().':'.$entity->getFileUri();

            $missing = $storage->get($id);

            if (!empty($missing)) {
                self::saveResolvedDependencies($entity, $missing);

                $storage->delete($id);
            }
        }

        if ($entity instanceof ConfigEntityInterface) {
            $shared_entity_id = $entity->id();
        } else {
            $shared_entity_id = $entity->uuid();
        }

        $id = $entity->getEntityTypeId().':'.$shared_entity_id;

        $missing = $storage->get($id);
        if (empty($missing)) {
            return;
        }

        self::saveResolvedDependencies($entity, $missing);

        $storage->delete($id);
    }

    /**
     * @param $entity
     * @param $missing
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\Core\Entity\EntityStorageException
     */
    protected static function saveResolvedDependencies($entity, $missing)
    {
        foreach ($missing as $sync) {
            $infos = EntityStatus::getInfosForEntity($sync[self::INDEX_ENTITY_TYPE], $sync[self::INDEX_ENTITY_ID]);
            foreach ($infos as $info) {
                if ($info->isDeleted()) {
                    break;
                }

                if (!$info->getPool() || !$info->getFlow()) {
                    continue;
                }

                $referenced_entity = $info->getEntity();

                $flow = $info->getFlow();
                if (!$flow->canPullEntity($referenced_entity->getEntityTypeId(), $referenced_entity->bundle(), PullIntent::PULL_FORCED)) {
                    continue;
                }

                if (!empty($sync[self::INDEX_SET_FIELD])) {
                    /**
                     * @var \Drupal\Core\Entity\FieldableEntityInterface $referenced_entity
                     */
                    if ('link' === $sync[self::INDEX_SET_FIELD] && 'menu_link_content' === $referenced_entity->getEntityTypeId()) {
                        if (isset($sync[self::INDEX_DATA]['enabled']) && $sync[self::INDEX_DATA]['enabled']) {
                            $referenced_entity->set('enabled', [['value' => 1]]);
                        }

                        $data = 'entity:'.$entity->getEntityTypeId().'/'.$entity->id();
                    } elseif ('crop' === $referenced_entity->getEntityTypeId() && 'entity_id' === $sync[self::INDEX_SET_FIELD]) {
                        $data = [
                            'value' => $entity->id(),
                        ];
                    } else {
                        $data = [
                            'target_id' => $entity->id(),
                        ];
                    }

                    $referenced_entity->set($sync[self::INDEX_SET_FIELD], $data);
                    $referenced_entity->save();

                    break;
                }

                try {
                    $info
                        ->getPool()
                        ->getClient()
                        ->getSyndicationService()
                        ->pullSingle($flow->id, $referenced_entity->getEntityTypeId(), $referenced_entity->bundle(), $referenced_entity->uuid())
                        ->fromPool($info->getPool()->id)
                        ->asDependency(PullIntent::PULL_AS_DEPENDENCY === $sync[self::INDEX_PULL_REASON])
                        ->manually(PullIntent::PULL_MANUALLY === $sync[self::INDEX_PULL_REASON])
                        ->execute();
                } catch (SyncCoreException $e) {
                    \Drupal::logger('cms_content_sync')->warning('Failed to pull %type.%bundle %entity_id through the missing dependency manager: @message<br>Flow: @flow_id | Pool: @pool_id', [
                        '%type' => $referenced_entity->getEntityTypeId(),
                        '%bundle' => $referenced_entity->bundle(),
                        '%entity_id' => $referenced_entity->uuid(),
                        '@message' => $e->getMessage(),
                        '@flow_id' => $flow->id,
                        '@pool_id' => $info->getPool()->id,
                    ]);
                }
            }
        }
    }
}
