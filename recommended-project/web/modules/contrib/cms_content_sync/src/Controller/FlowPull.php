<?php

namespace Drupal\cms_content_sync\Controller;

use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use EdgeBox\SyncCore\Exception\SyncCoreException;
use EdgeBox\SyncCore\Exception\TimeoutException;
use EdgeBox\SyncCore\Interfaces\ISyncCore;

/**
 * Pull controller.
 */
class FlowPull extends ControllerBase
{
    /**
     * Pull all entities of this Flow.
     *
     * @param $cms_content_sync_flow
     * @param $pull_mode
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     *
     * @return null|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function pull($cms_content_sync_flow, $pull_mode)
    {
        /**
         * @var \Drupal\cms_content_sync\Entity\Flow $flow
         */
        $flow = \Drupal::entityTypeManager()
            ->getStorage('cms_content_sync_flow')
            ->load($cms_content_sync_flow);

        $force_pull = false;
        if ('all_entities' == $pull_mode) {
            $force_pull = true;
        }

        $result = FlowPull::pullAll($flow, $force_pull);

        $operations = [];

        foreach ($result as $id => $operation) {
            $operations[] = [
                '\Drupal\cms_content_sync\Controller\FlowPull::batch',
                [$operation],
            ];
        }

        $batch = [
            'title' => t('Pull all'),
            'operations' => $operations,
            'finished' => '\Drupal\cms_content_sync\Controller\FlowPull::batchFinished',
        ];
        batch_set($batch);

        return batch_process(Url::fromRoute('entity.cms_content_sync_flow.collection'));
    }

    /**
     * Force pull an entity for a specific flow.
     *
     * @param $flow_id
     * @param $entity_type
     * @param $entity_uuid
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    public static function force_pull_entity($flow_id, $entity_type, $entity_uuid)
    {
        $entity = EntityStatus::getInfosForEntity($entity_type, $entity_uuid, ['flow' => $flow_id]);
        $entity = reset($entity);

        /** @var \Drupal\cms_content_sync\Entity\EntityStatus $entity */
        if ($entity instanceof EntityStatus) {
            // If the entity is embedded, we need to pull the parent entity instead.
            if ($entity->wasPulledEmbedded()) {
                $parent = $entity->getParentEntity();
                if (!$parent) {
                    $raw = $entity->getData(EntityStatus::DATA_PARENT_ENTITY);
                    \Drupal::messenger()->addMessage(t("The @type with the ID @uuid was pulled embedded into another entity but that parent @parent_type with ID @parent_uuid doesn't exist.", [
                        '@type' => $entity->get('entity_type')->getValue()[0]['value'],
                        '@uuid' => $entity->get('entity_uuid')->getValue()[0]['value'],
                        '@parent_type' => $raw['type'],
                        '@parent_uuid' => $raw['uuid'],
                    ]), 'warning');

                    return;
                }

                self::force_pull_entity($flow_id, $parent->getEntityTypeId(), $parent->uuid());
            }

            $source = $entity->getEntity();
            if (empty($source)) {
                \Drupal::messenger()->addMessage(t("The @type with the ID @uuid doesn't exist locally, pull skipped.", [
                    '@type' => $entity->get('entity_type')->getValue()[0]['value'],
                    '@uuid' => $entity->get('entity_uuid')->getValue()[0]['value'],
                ]), 'warning');

                return;
            }

            $pool = $entity->getPool();
            if (empty($pool)) {
                \Drupal::messenger()->addMessage(t('The Pool for @type %label doesn\'t exist anymore, push skipped.', [
                    '@type' => $entity->get('entity_type')->getValue()[0]['value'],
                    '%label' => $source->label(),
                ]), 'warning');

                return;
            }

            $entity_type_name = $source->getEntityTypeId();
            $entity_bundle = $source->bundle();

            $manual = false;

            $flow = $entity->getFlow();

            if (!$flow || !$flow->canPullEntity($entity_type_name, $entity_bundle, PullIntent::PULL_AUTOMATICALLY, SyncIntent::ACTION_CREATE, true)) {
                if ($flow && $flow->canPullEntity($entity_type_name, $entity_bundle, PullIntent::PULL_MANUALLY, SyncIntent::ACTION_CREATE, true)) {
                    $manual = true;
                } elseif (!$flow || !$flow->canPullEntity($entity_type_name, $entity_bundle, PullIntent::PULL_AS_DEPENDENCY, SyncIntent::ACTION_CREATE, true)) {
                    // The flow from the status entity no longer pulls this entity type / bundle => look for a new Flow to replace it.
                    $flow = Flow::getFlowForApiAndEntityType($pool, $entity_type_name, $entity_bundle, PullIntent::PULL_AUTOMATICALLY, SyncIntent::ACTION_CREATE, true);

                    if (!$flow) {
                        $flow = Flow::getFlowForApiAndEntityType($pool, $entity_type_name, $entity_bundle, PullIntent::PULL_MANUALLY, SyncIntent::ACTION_CREATE, true);
                        if ($flow) {
                            $manual = true;
                        } else {
                            $flow = Flow::getFlowForApiAndEntityType($pool, $entity_type_name, $entity_bundle, PullIntent::PULL_AS_DEPENDENCY, SyncIntent::ACTION_CREATE, true);
                            if (!$flow) {
                                \Drupal::messenger()->addMessage(t('No Flow exists to pull @type %label, pull skipped.', [
                                    '@type' => $entity->get('entity_type')->getValue()[0]['value'],
                                    '%label' => $source->label(),
                                ]), 'warning');

                                return;
                            }
                        }
                    }
                }
            }

            if ($source instanceof ConfigEntityInterface) {
                $shared_entity_id = $source->id();
            } else {
                $shared_entity_id = $source->uuid();
            }

            try {
                $pool
                    ->getClient()
                    ->getSyndicationService()
                    ->pullSingle($flow->id, $entity_type_name, $entity_bundle, $shared_entity_id)
                    ->fromPool($pool->id)
                    ->manually((bool) $manual)
                    ->execute();

                \Drupal::messenger()->addMessage(t('Pull of @type %label has been triggered.', [
                    '@type' => $entity->get('entity_type')->getValue()[0]['value'],
                    '%label' => $source->label(),
                ]));
            } catch (SyncCoreException $e) {
                \Drupal::messenger()->addMessage(t('Failed to pull @type %label: @message', [
                    '@type' => $entity->get('entity_type')->getValue()[0]['value'],
                    '%label' => $source->label(),
                    '@message' => $e->getMessage(),
                ]), 'warning');
            }
        } else {
            \Drupal::messenger()->addMessage(t('No local status entity found for Entity Type: @type having UUID: @uuid.', [
                '@type' => $entity_type,
                '@uuid' => $entity_uuid,
            ]), 'warning');

            return;
        }
    }

    /**
     * Kindly ask the Sync Core to pull all entities that are auto pulled.
     *
     * @param \Drupal\cms_content_sync\Entity\Flow $flow
     * @param bool                                 $force
     *
     * @return \EdgeBox\SyncCore\Interfaces\Syndication\IPullAll[]
     */
    public static function pullAll($flow, $force = false)
    {
        $flow_import = $force ? [PullIntent::PULL_AUTOMATICALLY] : [PullIntent::PULL_AUTOMATICALLY, PullIntent::PULL_MANUALLY];
        $pool_import = $force ? [Pool::POOL_USAGE_FORCE] : [Pool::POOL_USAGE_FORCE, Pool::POOL_USAGE_ALLOW];

        /**
         * @var \EdgeBox\SyncCore\Interfaces\Syndication\IPullAll[] $result
         */
        $result = [];

        $pools = Pool::getAll();

        foreach ($flow->getEntityTypeConfig() as $id => $type) {
            $entity_type_name = $type['entity_type_name'];
            $bundle_name = $type['bundle_name'];
            $version = $type['version'];

            if (Flow::HANDLER_IGNORE == $type['handler']) {
                continue;
            }

            if (!in_array($type['import'], $flow_import)) {
                continue;
            }

            $entity_type_pools = [];
            if (isset($type['import_pools'])) {
                foreach ($type['import_pools'] as $pool_id => $state) {
                    if (!isset($entity_type_pools[$pool_id])) {
                        $entity_type_pools[$pool_id] = [];
                    }
                    $entity_type_pools[$pool_id]['import'] = $state;
                }
            }

            foreach ($entity_type_pools as $pool_id => $definition) {
                if (empty($pools[$pool_id])) {
                    continue;
                }
                $pool = $pools[$pool_id];
                $import = $definition['import'] ?? null;

                if (!in_array($import, $pool_import)) {
                    continue;
                }

                $client = $pool
                    ->getClient();

                if ($client->featureEnabled(ISyncCore::FEATURE_PULL_ALL_WITHOUT_POOL)) {
                    $result[] = $client
                        ->getSyndicationService()
                        ->pullAll($flow->id, $entity_type_name, $bundle_name, $version)
                        ->force($force);

                    break;
                }

                $result[] = $client
                    ->getSyndicationService()
                    ->pullAll($flow->id, $entity_type_name, $bundle_name, $version)
                    ->fromPool($pool->id)
                    ->force($force);
            }
        }

        return $result;
    }

    /**
     * Batch pull finished callback.
     *
     * @param $success
     * @param $results
     * @param $operations
     */
    public static function batchFinished($success, $results, $operations)
    {
        $failed = 0;
        $empty = 0;
        $synchronized = 0;
        foreach ($results as $result) {
            if ('FAILURE' == $result['type']) {
                ++$failed;
            } elseif ('EMPTY' == $result['type']) {
                ++$empty;
            } else {
                $synchronized += $result['total'];
            }
        }

        if ($failed) {
            \Drupal::messenger()->addMessage(t('Failed to pull from %failed entity pools.', ['%failed' => $failed]));
        }
        if ($empty) {
            \Drupal::messenger()->addMessage(t('%empty entity pools were empty or had no new entities.', ['%empty' => $empty]));
        }
        if ($synchronized) {
            \Drupal::messenger()->addMessage(t('%synchronized entities have been pulled.', ['%synchronized' => $synchronized]));
        }
    }

    /**
     * Batch pull callback for the pull-all operation.
     *
     * @param \EdgeBox\SyncCore\Interfaces\Syndication\IPullAll $operation
     * @param array                                             $context
     *
     * @throws \EdgeBox\SyncCore\Exception\SyncCoreException
     */
    public static function batch($operation, &$context)
    {
        if (empty($context['sandbox']['operation'])) {
            $context['sandbox']['operation'] = $operation->execute();

            if (!$operation->total()) {
                $context['results'][] = [
                    'type' => 'EMPTY',
                ];

                return;
            }
        }

        /**
         * @var \EdgeBox\SyncCore\Interfaces\Syndication\IPullAll $operation
         */
        $operation = $context['sandbox']['operation'];

        try {
            $progress = $operation->progress();
            $total = $operation->total();

            if ($progress < $total) {
                // Don't spam the Sync Core...
                sleep(5);
            }

            if ($progress == $total) {
                $context['results'][] = ['type' => 'success', 'total' => $total];
            }

            $context['finished'] = $progress / $operation->total();
            $context['message'] = 'Pulled '.$progress.' of '.$operation->total().' '.$operation->getTypeMachineName().'.'.$operation->getBundleMachineName().' from '.$operation->getSourceName().'...';
        }
        // Ignore timeouts, just wait until the Sync Core becomes responsive again.
        catch (TimeoutException $e) {
        }
    }
}
