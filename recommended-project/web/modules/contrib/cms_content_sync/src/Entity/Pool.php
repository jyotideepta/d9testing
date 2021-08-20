<?php

namespace Drupal\cms_content_sync\Entity;

use Drupal\cms_content_sync\Controller\Migration;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncCoreInterface\SyncCoreFactory;
use Drupal\cms_content_sync\SyncCorePoolExport;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Defines the "Content Sync - Pool" entity.
 *
 * @ConfigEntityType(
 *   id = "cms_content_sync_pool",
 *   label = @Translation("Content Sync - Pool"),
 *   handlers = {
 *     "list_builder" = "Drupal\cms_content_sync\Controller\PoolListBuilder",
 *     "form" = {
 *       "add" = "Drupal\cms_content_sync\Form\PoolForm",
 *       "edit" = "Drupal\cms_content_sync\Form\PoolForm",
 *       "delete" = "Drupal\cms_content_sync\Form\PoolDeleteForm",
 *     }
 *   },
 *   config_prefix = "pool",
 *   admin_permission = "administer cms content sync",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "backend_url",
 *   },
 *   links = {
 *     "edit-form" = "/admin/config/services/cms_content_sync/pool/{cms_content_sync_pool}/edit",
 *     "delete-form" = "/admin/config/services/cms_content_sync/synchronizations/{cms_content_sync_pool}/delete",
 *   }
 * )
 */
class Pool extends ConfigEntityBase implements PoolInterface
{
    public const V2_STATUS_NONE = '';
    public const V2_STATUS_EXPORTED = 'exported';
    public const V2_STATUS_ACTIVE = 'active';

    /**
     * @var string POOL_USAGE_FORBID Forbid usage of this pool for this flow
     */
    public const POOL_USAGE_FORBID = 'forbid';
    /**
     * @var string POOL_USAGE_ALLOW Allow usage of this pool for this flow
     */
    public const POOL_USAGE_ALLOW = 'allow';
    /**
     * @var string POOL_USAGE_FORCE Force usage of this pool for this flow
     */
    public const POOL_USAGE_FORCE = 'force';

    /**
     * The Pool ID.
     *
     * @var string
     */
    public $id;

    /**
     * The Pool label.
     *
     * @var string
     */
    public $label;

    /**
     * The Pool Sync Core backend URL.
     *
     * @var string
     */
    public $backend_url;

    /**
     * The authentication type to use.
     * See Pool::AUTHENTICATION_TYPE_* for details.
     *
     * @deprecated Will be removed with the 2.0 release.
     *
     * @var string
     */
    public $authentication_type;

    /**
     * The unique site identifier.
     *
     * @deprecated Will be removed with the 2.0 release.
     *
     * @var string
     */
    public $site_id;

    /**
     * @var \EdgeBox\SyncCore\V1\SyncCore
     */
    protected $client;

    /**
     * @param mixed $fresh
     *
     * @return \EdgeBox\SyncCore\Interfaces\ISyncCore
     */
    public function getClient($fresh = false)
    {
        if (!$this->client || $fresh) {
            if ($this->useV2()) {
                $this->client = SyncCoreFactory::getSyncCoreV2();
            } else {
                $this->client = SyncCoreFactory::getSyncCore($this->getSyncCoreUrl());
            }
        }

        return $this->client;
    }

    /**
     * {@inheritdoc}
     */
    public static function preDelete(EntityStorageInterface $storage, array $entities)
    {
        parent::preDelete($storage, $entities);

        try {
            foreach ($entities as $entity) {
                $handler = new SyncCorePoolExport($entity);
                // $handler->remove(FALSE);
            }
        } catch (RequestException $e) {
            $messenger = \Drupal::messenger();
            $messenger->addError(t('The Sync Core server could not be accessed. Please check the connection.'));

            throw new AccessDeniedHttpException();
        }
    }

    /**
     * Get a list of all sites from all pools that use a different version ID and
     * provide a diff on field basis.
     *
     * @param string $entity_type
     * @param string $bundle
     *
     * @throws \Exception
     *
     * @return array pool => site_id[]
     */
    public static function getAllSitesWithDifferentEntityTypeVersion($entity_type, $bundle)
    {
        $result = [];

        foreach (Pool::getAll() as $pool_id => $pool) {
            $diff = $pool->getClient()->getSitesWithDifferentEntityTypeVersion($pool->id, $entity_type, $bundle, Flow::getEntityTypeVersion($entity_type, $bundle));
            if (empty($diff)) {
                continue;
            }
            $result[$pool_id] = $diff;
        }

        return $result;
    }

    /**
     * Get a list of all sites for all pools that are using this entity.
     * Only works for pools that are connected to the entity on this site.
     *
     * @param \Drupal\Core\Entity\EntityInterface $entity
     *
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * @return array pool => site_id[]
     */
    public static function getAllExternalUsages($entity)
    {
        $entity_type = $entity->getEntityTypeId();
        $bundle = $entity->bundle();
        $entity_uuid = $entity->uuid();

        $result = [];

        foreach (EntityStatus::getInfosForEntity($entity_type, $entity_uuid) as $status) {
            $pool = $status->getPool();
            if (empty($pool)) {
                continue;
            }

            $pool_id = $pool->id;
            if (isset($result[$pool_id])) {
                continue;
            }

            if ($entity instanceof ConfigEntityInterface) {
                $shared_entity_id = $entity->id();
            } else {
                $shared_entity_id = $entity_uuid;
            }

            $result[$pool_id] = $pool
                ->getClient()
                ->getSyndicationService()
                ->getExternalUsages($pool_id, $entity_type, $bundle, $shared_entity_id);
        }

        return $result;
    }

    /**
     * Returns the Sync Core URL for this pool.
     *
     * @return string
     */
    public function getSyncCoreUrl()
    {
        if ($this->useV2()) {
            return SyncCoreFactory::getSyncCoreV2Url();
        }

        // Check if the BackendUrl got overwritten.
        $cms_content_sync_settings = Settings::get('cms_content_sync');
        if (isset($cms_content_sync_settings, $cms_content_sync_settings['pools'][$this->id]['backend_url'])) {
            return $cms_content_sync_settings['pools'][$this->id]['backend_url'];
        }

        return $this->backend_url;
    }

    /**
     * Get the newest pull/push timestamp for this pool from all status
     * entities that exist for the given entity.
     *
     * @param $entity_type
     * @param $entity_uuid
     * @param bool $pull
     *
     * @return null|int
     */
    public function getNewestTimestamp($entity_type, $entity_uuid, $pull = true)
    {
        $entity_status = EntityStatus::getInfoForPool($entity_type, $entity_uuid, $this);
        $timestamp = null;
        foreach ($entity_status as $info) {
            $item_timestamp = $pull ? $info->getLastPull() : $info->getLastPush();
            if ($item_timestamp) {
                if (!$timestamp || $timestamp < $item_timestamp) {
                    $timestamp = $item_timestamp;
                }
            }
        }

        return $timestamp;
    }

    /**
     * Get the newest pull/push timestamp for this pool from all status
     * entities that exist for the given entity.
     *
     * @param $entity_type
     * @param $entity_uuid
     * @param int  $timestamp
     * @param bool $pull
     */
    public function setTimestamp($entity_type, $entity_uuid, $timestamp, $pull = true)
    {
        $entity_status = EntityStatus::getInfoForPool($entity_type, $entity_uuid, $this);
        foreach ($entity_status as $info) {
            if ($pull) {
                $info->setLastPull($timestamp);
            } else {
                $info->setLastPush($timestamp);
            }
            $info->save();
        }
    }

    /**
     * Mark the entity as deleted in this pool (reflected on all entity status
     * entities related to this pool).
     *
     * @param $entity_type
     * @param $entity_uuid
     */
    public function markDeleted($entity_type, $entity_uuid)
    {
        $entity_status = EntityStatus::getInfoForPool($entity_type, $entity_uuid, $this);
        foreach ($entity_status as $info) {
            $info->isDeleted(true);
            $info->save();
        }
    }

    /**
     * Check whether this entity has been deleted intentionally already. In this
     * case we ignore push and pull intents for it.
     *
     * @param $entity_type
     * @param $entity_uuid
     *
     * @return bool
     */
    public function isEntityDeleted($entity_type, $entity_uuid)
    {
        $entity_status = EntityStatus::getInfoForPool($entity_type, $entity_uuid, $this);
        foreach ($entity_status as $info) {
            if ($info->isDeleted()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Load all cms_content_sync_pool entities.
     *
     * @return Pool[]
     */
    public static function getAll()
    {
        /**
         * @var Pool[] $configurations
         */
        return \Drupal::entityTypeManager()
            ->getStorage('cms_content_sync_pool')
            ->loadMultiple();
    }

    /**
     * Returns an list of pools that can be selected for an entity type.
     *
     * @oaram string $entity_type
     *  The entity type the pools should be returned for.
     *
     * @param string                              $bundle
     *                                                           The bundle the pools should be returned for
     * @param \Drupal\Core\Entity\EntityInterface $parent_entity
     *                                                           The
     *                                                           parent entity, if any. Only required if $field_name is given-.
     * @param string                              $field_name
     *                                                           The name of the parent entity field that
     *                                                           references this entity. In this case if the field handler is set to
     *                                                           "automatically push referenced entities", the user doesn't have to
     *                                                           make a choice as it is set automatically anyway.
     * @param mixed                               $entity_type
     *
     * @return array $selectable_pools
     */
    public static function getSelectablePools($entity_type, $bundle, $parent_entity = null, $field_name = null)
    {
        // Get all available flows.
        $flows = Flow::getAll();
        $configs = [];
        $selectable_pools = [];
        $selectable_flows = [];

        // When editing the entity directly, the "push as reference" flows won't be available and vice versa.
        $root_entity = !$parent_entity && !$field_name;
        if ($root_entity) {
            $allowed_push_options = [PushIntent::PUSH_FORCED, PushIntent::PUSH_MANUALLY, PushIntent::PUSH_AUTOMATICALLY];
        } else {
            $allowed_push_options = [PushIntent::PUSH_FORCED, PushIntent::PUSH_AS_DEPENDENCY];
        }

        foreach ($flows as $flow_id => $flow) {
            $flow_entity_config = $flow->getEntityTypeConfig($entity_type, $bundle);
            if (empty($flow_entity_config)) {
                continue;
            }
            if ('ignore' == $flow_entity_config['handler']) {
                continue;
            }
            if (!in_array($flow_entity_config['export'], $allowed_push_options)) {
                continue;
            }
            if ($parent_entity && $field_name) {
                $parent_flow_config = $flow->sync_entities[$parent_entity->getEntityTypeId().'-'.$parent_entity->bundle().'-'.$field_name];
                if (!empty($parent_flow_config['handler_settings']['export_referenced_entities'])) {
                    continue;
                }
            }

            $selectable_flows[$flow_id] = $flow;

            $configs[$flow_id] = [
                'flow_label' => $flow->label(),
                'flow' => $flow->getEntityTypeConfig($entity_type, $bundle),
            ];
        }

        foreach ($configs as $config_id => $config) {
            if (in_array('allow', $config['flow']['export_pools'])) {
                $selectable_pools[$config_id]['flow_label'] = $config['flow_label'];
                $selectable_pools[$config_id]['widget_type'] = $config['flow']['pool_export_widget_type'];
                foreach ($config['flow']['export_pools'] as $pool_id => $push_to_pool) {
                    // Filter out all pools with configuration "allow".
                    if (self::POOL_USAGE_ALLOW == $push_to_pool) {
                        $pool_entity = \Drupal::entityTypeManager()->getStorage('cms_content_sync_pool')
                            ->loadByProperties(['id' => $pool_id]);
                        $pool_entity = reset($pool_entity);
                        $selectable_pools[$config_id]['pools'][$pool_id] = $pool_entity->label();
                    }
                }
            }
        }

        return $selectable_pools;
    }

    /**
     * Reset the status entities for this pool.
     *
     * @param string $pool_id
     *                        The pool the status entities should be reset for
     */
    public static function resetStatusEntities($pool_id = '')
    {
        // Reset the entity status.
        $status_storage = \Drupal::entityTypeManager()
            ->getStorage('cms_content_sync_entity_status');

        $connection = \Drupal::database();

        // For a single pool.
        if (!empty($pool_id)) {
            // Save flags to status entities that they have been reset.
            $connection->query('UPDATE cms_content_sync_entity_status SET flags=flags|:flag WHERE last_export IS NOT NULL AND pool=:pool', [
                ':flag' => EntityStatus::FLAG_LAST_PUSH_RESET,
                ':pool' => $pool_id,
            ]);
            $connection->query('UPDATE cms_content_sync_entity_status SET flags=flags|:flag WHERE last_import IS NOT NULL AND pool=:pool', [
                ':flag' => EntityStatus::FLAG_LAST_PULL_RESET,
                ':pool' => $pool_id,
            ]);

            // Actual reset.
            $db_query = $connection->update($status_storage->getBaseTable());
            $db_query->fields([
                'last_export' => null,
                'last_import' => null,
            ]);
            $db_query->condition('pool', $pool_id);
            $db_query->execute();
        }
        // For all pools.
        else {
            // Save flags to status entities that they have been reset.
            $connection->query('UPDATE cms_content_sync_entity_status SET flags=flags|:flag WHERE last_export IS NOT NULL', [
                ':flag' => EntityStatus::FLAG_LAST_PUSH_RESET,
            ]);
            $connection->query('UPDATE cms_content_sync_entity_status SET flags=flags|:flag WHERE last_import IS NOT NULL', [
                ':flag' => EntityStatus::FLAG_LAST_PULL_RESET,
            ]);

            // Actual reset.
            $db_query = $connection->update($status_storage->getBaseTable());
            $db_query->fields([
                'last_export' => null,
                'last_import' => null,
            ]);
            $db_query->execute();
        }

        // Invalidate cache by storage.
        $status_storage->resetCache();

        // Above cache clearing doesn't work reliably. So we reset the whole entity cache.
        \Drupal::service('cache.entity')->deleteAll();
    }

    /**
     * Create a pool configuration programmatically.
     *
     * @param $pool_name
     * @param string $pool_id
     * @param $backend_url
     * @param $authentication_type
     */
    public static function createPool($pool_name, $pool_id, $backend_url, $authentication_type)
    {
        // If no pool_id is given, create one.
        if (empty($pool_id)) {
            $pool_id = strtolower($pool_name);
            $pool_id = preg_replace('@[^a-z0-9_]+@', '_', $pool_id);
        }

        $pools = Pool::getAll();
        if (array_key_exists($pool_id, $pools)) {
            \Drupal::messenger()->addMessage('A pool with the machine name '.$pool_id.' does already exist. Therefor the creation has been skipped.', 'warning');
        } else {
            $uuid_service = \Drupal::service('uuid');
            $language_manager = \Drupal::service('language_manager');
            $default_language = $language_manager->getDefaultLanguage();

            $pool_config = \Drupal::service('config.factory')->getEditable('cms_content_sync.pool.'.$pool_id);
            $pool_config
                ->set('uuid', $uuid_service->generate())
                ->set('langcode', $default_language->getId())
                ->set('status', true)
                ->set('id', $pool_id)
                ->set('label', $pool_name)
                ->set('backend_url', $backend_url)
                ->set('authentication_type', $authentication_type)
                ->save();
        }

        return $pool_id;
    }

    public function useV2()
    {
        return self::V2_STATUS_ACTIVE === $this->getV2Status() || Migration::useV2();
    }

    public function v2Ready()
    {
        $status = $this->getV2Status();

        return self::V2_STATUS_ACTIVE === $status || self::V2_STATUS_EXPORTED === $status;
    }

    public function getV2Status()
    {
        return Migration::getPoolStatus($this);
    }
}
