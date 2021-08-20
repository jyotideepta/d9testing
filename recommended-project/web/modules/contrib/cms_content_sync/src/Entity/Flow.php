<?php

namespace Drupal\cms_content_sync\Entity;

use Drupal\cms_content_sync\Controller\ContentSyncSettings;
use Drupal\cms_content_sync\Controller\Migration;
use Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityInterface;

/**
 * Defines the "Content Sync - Flow" entity.
 *
 * @ConfigEntityType(
 *   id = "cms_content_sync_flow",
 *   label = @Translation("Content Sync - Flow"),
 *   handlers = {
 *     "list_builder" = "Drupal\cms_content_sync\Controller\FlowListBuilder",
 *     "form" = {
 *       "add" = "Drupal\cms_content_sync\Form\FlowForm",
 *       "edit" = "Drupal\cms_content_sync\Form\FlowForm",
 *       "delete" = "Drupal\cms_content_sync\Form\FlowDeleteForm",
 *       "copy_remote" = "Drupal\cms_content_sync\Form\CopyRemoteFlow",
 *     }
 *   },
 *   config_prefix = "flow",
 *   admin_permission = "administer cms content sync",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "name",
 *   },
 *   config_export = {
 *     "id",
 *     "name",
 *     "sync_entities",
 *   },
 *   links = {
 *     "edit-form" = "/admin/config/services/cms_content_sync/synchronizations/{cms_content_sync_flow}/edit",
 *     "delete-form" = "/admin/config/services/cms_content_sync/synchronizations/{cms_content_sync_flow}/delete",
 *   }
 * )
 */
class Flow extends ConfigEntityBase implements FlowInterface
{
    /**
     * @var string HANDLER_IGNORE
     *             Ignore this entity type / bundle / field completely
     */
    public const HANDLER_IGNORE = 'ignore';

    /**
     * @var string PREVIEW_DISABLED
     *             Hide these entities completely
     */
    public const PREVIEW_DISABLED = 'disabled';

    /**
     * @var string PREVIEW_TABLE
     *             Show these entities in a table view
     */
    public const PREVIEW_TABLE = 'table';

    /**
     * This Flow pushes entities.
     */
    public const TYPE_PUSH = 'push';

    /**
     * This Flow pulls entities.
     */
    public const TYPE_PULL = 'pull';

    /**
     * This Flow pushes and pulls entities.
     *
     * @deprecated will be removed in v2
     */
    public const TYPE_BOTH = 'both';

    public const V2_STATUS_NONE = '';
    public const V2_STATUS_EXPORTED = 'exported';
    public const V2_STATUS_ACTIVE = 'active';

    /**
     * The Flow ID.
     *
     * @var string
     */
    public $id;

    /**
     * The Flow name.
     *
     * @var string
     */
    public $name;

    /**
     * The Flow entities.
     *
     * @todo Refactor to be hierarchical, so entity_type => bundle_name  and within that add a ['fields'] config array.
     *
     * @var array
     */
    public $sync_entities;

    /**
     * @var Flow[]
     *             All content synchronization configs. Use {@see Flow::getAll}
     *             to request them.
     */
    public static $all = null;

    /**
     * @return null|string
     */
    public function getType()
    {
        static $has_push = null;
        static $has_pull = null;
        if (null === $has_push || null === $has_pull) {
            if (empty($this->sync_entities)) {
                return null;
            }

            foreach ($this->getEntityTypeConfig() as $config) {
                if (PushIntent::PUSH_DISABLED != $config['export']) {
                    $has_push = true;
                    if ($has_pull) {
                        break;
                    }
                }

                if (PullIntent::PULL_DISABLED != $config['import']) {
                    $has_pull = true;
                    if ($has_push) {
                        break;
                    }
                }
            }
        }

        if ($has_push) {
            if ($has_pull) {
                return self::TYPE_BOTH;
            }

            return self::TYPE_PUSH;
        }
        if ($has_pull) {
            return self::TYPE_PULL;
        }

        return null;
    }

    /**
     * Ensure that pools are pulled before the flows.
     */
    public function calculateDependencies()
    {
        parent::calculateDependencies();

        foreach ($this->getUsedPools() as $pool) {
            $this->addDependency('config', 'cms_content_sync.pool.'.$pool->id);
        }
    }

    /**
     * Get all flows pushing this entity.
     *
     * @param \Drupal\Core\Entity\EntityInterface $entity
     * @param $action
     * @param bool $include_dependencies
     *
     * @throws \Exception
     *
     * @return array|Flow[]
     */
    public static function getFlowsForPushing($entity, $action, $include_dependencies = true)
    {
        if (SyncIntent::ACTION_DELETE === $action) {
            $last_push = EntityStatus::getLastPushForEntity($entity);
            if (empty($last_push)) {
                return [];
            }
        }

        $flows = PushIntent::getFlowsForEntity(
            $entity,
            PushIntent::PUSH_AUTOMATICALLY,
            $action
        );

        if (!count($flows) && SyncIntent::ACTION_DELETE === $action) {
            $flows = PushIntent::getFlowsForEntity(
                $entity,
                PushIntent::PUSH_MANUALLY,
                $action
            );
        }

        if ($include_dependencies && !count($flows)) {
            $flows = PushIntent::getFlowsForEntity(
                $entity,
                PushIntent::PUSH_AS_DEPENDENCY,
                $action
            );
            if (count($flows)) {
                $infos = EntityStatus::getInfosForEntity(
                    $entity->getEntityTypeId(),
                    $entity->uuid()
                );

                $pushed = [];
                foreach ($infos as $info) {
                    if (!in_array($info->getFlow(), $flows)) {
                        continue;
                    }
                    if (in_array($info->getFlow(), $pushed)) {
                        continue;
                    }
                    if (!$info->getLastPush()) {
                        continue;
                    }
                    $pushed[] = $info->getFlow();
                }
                $flows = $pushed;
            }
        }

        return $flows;
    }

    /**
     * Get a unique version hash for the configuration of the provided entity type
     * and bundle.
     *
     * @param string $type_name
     *                            The entity type in question
     * @param string $bundle_name
     *                            The bundle in question
     *
     * @return string
     *                A 32 character MD5 hash of all important configuration for this entity
     *                type and bundle, representing it's current state and allowing potential
     *                conflicts from entity type updates to be handled smoothly
     */
    public static function getEntityTypeVersion($type_name, $bundle_name)
    {
        // @todo Include export_config keys in version definition for config entity types like webforms.
        if (EntityHandlerPluginManager::isEntityTypeFieldable($type_name)) {
            $entityFieldManager = \Drupal::service('entity_field.manager');
            $field_definitions = $entityFieldManager->getFieldDefinitions($type_name, $bundle_name);

            $field_definitions_array = (array) $field_definitions;
            unset($field_definitions_array['field_cms_content_synced']);

            $field_names = array_keys($field_definitions_array);
            sort($field_names);

            $version = json_encode($field_names);
        } else {
            $version = '';
        }

        return md5($version);
    }

    /**
     * Check whether the local deletion of the given entity is allowed.
     *
     * @return bool
     */
    public static function isLocalDeletionAllowed(EntityInterface $entity)
    {
        if (!$entity->uuid()) {
            return true;
        }
        $entity_status = EntityStatus::getInfosForEntity(
            $entity->getEntityTypeId(),
            $entity->uuid()
        );
        foreach ($entity_status as $info) {
            if (!$info->getLastPull() || $info->isSourceEntity()) {
                continue;
            }
            $flow = $info->getFlow();
            if (!$flow) {
                continue;
            }

            $config = $flow->getEntityTypeConfig($entity->getEntityTypeId(), $entity->bundle(), true);
            if (PullIntent::PULL_DISABLED === $config['import']) {
                continue;
            }
            if (!boolval($config['import_deletion_settings']['allow_local_deletion_of_import'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get a list of all pools this Flow is using.
     *
     * @return Pool[]
     */
    public function getUsedPools()
    {
        $result = [];

        $pools = Pool::getAll();

        foreach ($pools as $id => $pool) {
            if ($this->usesPool($pool)) {
                $result[$id] = $pool;
            }
        }

        return $result;
    }

    /**
     * Get a list of all pools that are used for pushing this entity, either
     * automatically or manually selected.
     *
     * @param string $entity_type
     * @param string $bundle
     *
     * @return Pool[]
     */
    public function getUsedPoolsForPulling($entity_type, $bundle)
    {
        $config = $this->getEntityTypeConfig($entity_type, $bundle);

        if (empty($config['import_pools'])) {
            return [];
        }

        $result = [];
        $pools = Pool::getAll();

        foreach ($config['import_pools'] as $id => $setting) {
            $pool = $pools[$id];

            if (Pool::POOL_USAGE_FORBID == $setting) {
                continue;
            }

            $result[] = $pool;
        }

        return $result;
    }

    /**
     * Get a list of all pools that are used for pushing this entity, either
     * automatically or manually selected.
     *
     * @param string|string[] $reason
     *                                        {@see Flow::PUSH_*}
     * @param string          $action
     *                                        {@see ::ACTION_*}
     * @param bool            $include_forced
     *                                        Include forced pools. Otherwise only use-selected / referenced ones.
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     *
     * @return Pool[]
     */
    public function getPoolsToPushTo(EntityInterface $entity, $reason, $action, $include_forced = true)
    {
        $config = $this->getEntityTypeConfig($entity->getEntityTypeId(), $entity->bundle());
        if (!$this->canPushEntity($entity, $reason, $action)) {
            return [];
        }

        $result = [];
        $pools = Pool::getAll();

        foreach ($config['export_pools'] as $id => $setting) {
            if (!isset($pools[$id])) {
                continue;
            }
            $pool = $pools[$id];

            if (Pool::POOL_USAGE_FORBID == $setting) {
                continue;
            }

            if (Pool::POOL_USAGE_FORCE == $setting) {
                if ($include_forced) {
                    $result[$id] = $pool;
                }

                continue;
            }

            $entity_status = EntityStatus::getInfoForEntity($entity->getEntityTypeId(), $entity->uuid(), $this, $pool);
            if ($entity_status && $entity_status->isPushEnabled()) {
                $result[$id] = $pool;
            }
        }

        return $result;
    }

    /**
     * Ask this Flow whether or not it can push the given entity.
     *
     * @param string|string[] $reason
     * @param string          $action
     * @param null|Pool       $pool
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     *
     * @return bool
     */
    public function canPushEntity(EntityInterface $entity, $reason, $action = SyncIntent::ACTION_CREATE, $pool = null)
    {
        $infos = $entity->uuid() ? EntityStatus::getInfosForEntity(
            $entity->getEntityTypeId(),
            $entity->uuid()
        ) : [];

        // Fresh entity- no pool restriction.
        if (!count($infos) || null !== $pool) {
            return $this->canPushEntityType($entity->getEntityTypeId(), $entity->bundle(), $reason, $action, $pool);
        }

        // If the entity has been pulled or pushed before, only the Flows that support the pools that were assigned
        // are relevant. So we filter out any Flows here that don't support any of the assigned pools.
        foreach ($infos as $info) {
            if ($this->canPushEntityType($entity->getEntityTypeId(), $entity->bundle(), $reason, $action, $info->getPool())) {
                return true;
            }
        }

        // Flow config may have changed so status entities exist but now they no longer push the entity. In this case we
        // fall back into the behavior as if the entity was new (see above)
        return $this->canPushEntityType($entity->getEntityTypeId(), $entity->bundle(), $reason, $action, $pool);
    }

    /**
     * Ask this Flow whether or not it can push the given entity type and optionally bundle.
     *
     * @param string          $entity_type_name
     * @param null|string     $bundle_name
     * @param string|string[] $reason
     * @param string          $action
     * @param null|Pool       $pool
     *
     * @return bool
     */
    public function canPushEntityType($entity_type_name, $bundle_name, $reason, $action = SyncIntent::ACTION_CREATE, $pool = null)
    {
        $any_reason = [
            PushIntent::PUSH_AUTOMATICALLY,
            PushIntent::PUSH_MANUALLY,
            PushIntent::PUSH_AS_DEPENDENCY,
        ];

        if (is_string($reason)) {
            if (PushIntent::PUSH_ANY === $reason || PushIntent::PUSH_FORCED === $reason) {
                $reason = $any_reason;
            } else {
                $reason = [$reason];
            }
        }

        if (!$bundle_name) {
            foreach ($this->getEntityTypeConfig($entity_type_name) as $config) {
                if ($this->canPushEntityType($entity_type_name, $config['bundle_name'], $reason, $action, $pool)) {
                    return true;
                }
            }

            return false;
        }

        $config = $this->getEntityTypeConfig($entity_type_name, $bundle_name);
        if (empty($config) || self::HANDLER_IGNORE == $config['handler']) {
            return false;
        }

        if (PushIntent::PUSH_DISABLED == $config['export']) {
            return false;
        }

        if (SyncIntent::ACTION_DELETE == $action && !boolval($config['export_deletion_settings']['export_deletion'])) {
            return false;
        }

        if ($pool) {
            if (empty($config['export_pools'][$pool->id]) || Pool::POOL_USAGE_FORBID == $config['export_pools'][$pool->id]) {
                return false;
            }
        }

        return in_array($config['export'], $reason);
    }

    /**
     * Load all entities.
     *
     * Load all cms_content_sync_flow entities and add overrides from global $config.
     *
     * @param bool $skip_inactive
     *                            Do not return inactive flows by default
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     *
     * @return Flow[]
     */
    public static function getAll($skip_inactive = true)
    {
        if ($skip_inactive && null !== self::$all) {
            return self::$all;
        }

        /**
         * @var Flow[] $configurations
         */
        $configurations = \Drupal::entityTypeManager()
            ->getStorage('cms_content_sync_flow')
            ->loadMultiple();

        foreach ($configurations as $id => &$configuration) {
            global $config;
            $config_name = 'cms_content_sync.flow.'.$id;
            if (!isset($config[$config_name]) || empty($config[$config_name])) {
                continue;
            }

            foreach ($config[$config_name] as $key => $new_value) {
                if ('sync_entities' == $key) {
                    foreach ($new_value as $sync_key => $options) {
                        foreach ($options as $options_key => $setting) {
                            if (is_array($setting)) {
                                foreach ($setting as $setting_key => $set) {
                                    $configuration->sync_entities[$sync_key][$options_key][$setting_key] = $set;
                                }
                            } else {
                                $configuration->sync_entities[$sync_key][$options_key] = $setting;
                            }
                        }
                    }

                    continue;
                }
                $configuration->set($key, $new_value);
            }
            $configuration->getEntityTypeConfig();
        }

        if ($skip_inactive) {
            $result = [];
            foreach ($configurations as $id => $flow) {
                if ($flow->get('status')) {
                    $result[$id] = $flow;
                }
            }

            $configurations = $result;

            self::$all = $configurations;
        }

        return $configurations;
    }

    public static function resetFlowCache()
    {
        self::$all = null;
    }

    /**
     * Get the first synchronization that allows the pull of the provided entity
     * type.
     *
     * @param Pool   $pool
     * @param string $entity_type_name
     * @param string $bundle_name
     * @param string $reason
     * @param string $action
     * @param bool   $strict
     *
     * @return null|Flow
     */
    public static function getFlowForApiAndEntityType($pool, $entity_type_name, $bundle_name, $reason, $action = SyncIntent::ACTION_CREATE, $strict = false)
    {
        $flows = self::getAll();

        // If $reason is DEPENDENCY and there's a Flow pulling AUTOMATICALLY we take that. But only if there's no Flow
        // explicitly handling this entity AS_DEPENDENCY.
        $fallback = null;

        foreach ($flows as $flow) {
            if ($pool && !in_array($pool, $flow->getUsedPoolsForPulling($entity_type_name, $bundle_name))) {
                continue;
            }

            if (!$flow->canPullEntity($entity_type_name, $bundle_name, $reason, $action, true)) {
                if (!$strict && $flow->canPullEntity($entity_type_name, $bundle_name, $reason, $action, false)) {
                    $fallback = $flow;
                }

                continue;
            }

            return $flow;
        }

        if (!empty($fallback)) {
            return $fallback;
        }

        return null;
    }

    /**
     * Ask this Flow whether or not it can push the provided entity.
     *
     * @param string $entity_type_name
     * @param string $bundle_name
     * @param string $reason
     * @param string $action
     * @param bool   $strict
     *                                 If asking for DEPENDENCY as a $reason, then $strict will NOT include a Flow that pulls AUTOMATICALLY
     *
     * @return bool
     */
    public function canPullEntity($entity_type_name, $bundle_name, $reason, $action = SyncIntent::ACTION_CREATE, $strict = false)
    {
        $config = $this->getEntityTypeConfig($entity_type_name, $bundle_name);
        if (empty($config) || self::HANDLER_IGNORE == $config['handler']) {
            return false;
        }

        if (PullIntent::PULL_DISABLED == $config['import']) {
            return false;
        }

        if (SyncIntent::ACTION_DELETE == $action && !boolval($config['import_deletion_settings']['import_deletion'])) {
            return false;
        }

        // If any handler is available, we can pull this entity.
        if (PullIntent::PULL_FORCED == $reason) {
            return true;
        }

        // Flows that pull automatically can also handle referenced entities.
        if (PullIntent::PULL_AUTOMATICALLY == $config['import']) {
            if (PullIntent::PULL_AS_DEPENDENCY == $reason && !$strict) {
                return true;
            }
        }

        // Once pulled manually, updates will arrive automatically.
        if (PullIntent::PULL_AUTOMATICALLY == $reason && PullIntent::PULL_MANUALLY == $config['import']) {
            if (SyncIntent::ACTION_UPDATE == $action || SyncIntent::ACTION_DELETE == $action) {
                return true;
            }
        }

        return $config['import'] == $reason;
    }

    /**
     * Ask this synchronization whether it supports the provided entity.
     * Returns false if either the entity type is not known or the config handler
     * is set to {@see Flow::HANDLER_IGNORE}.
     *
     * @return bool
     */
    public function supportsEntity(EntityInterface $entity)
    {
        $config = $this->getEntityTypeConfig($entity->getEntityTypeId(), $entity->bundle());
        if (empty($config) || empty($config['handler'])) {
            return false;
        }

        return self::HANDLER_IGNORE != $config['handler'];
    }

    /**
     * Check if the given pool is used by this Flow. If any handler set the flow
     * as FORCE or ALLOW, this will return TRUE.
     *
     * @param Pool $pool
     *
     * @return bool
     */
    public function usesPool($pool)
    {
        foreach ($this->getEntityTypeConfig(null, null, true) as $config) {
            if (Flow::HANDLER_IGNORE == $config['handler']) {
                continue;
            }

            if (PushIntent::PUSH_DISABLED != $config['export']) {
                if (!empty($config['export_pools'][$pool->id]) && Pool::POOL_USAGE_FORBID != $config['export_pools'][$pool->id]) {
                    return true;
                }
            }

            if (PullIntent::PULL_DISABLED != $config['import']) {
                if (!empty($config['import_pools'][$pool->id]) && Pool::POOL_USAGE_FORBID != $config['import_pools'][$pool->id]) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Unset the flow version warning.
     */
    public function resetVersionWarning()
    {
        $moduleHandler = \Drupal::service('module_handler');
        if ($moduleHandler->moduleExists('cms_content_sync_developer')) {
            $developer_config = \Drupal::service('config.factory')->getEditable('cms_content_sync.developer');
            $mismatching_versions = $developer_config->get('version_mismatch');
            if (!empty($mismatching_versions)) {
                unset($mismatching_versions[$this->id()]);
                $developer_config->set('version_mismatch', $mismatching_versions)->save();
            }
        }
    }

    /**
     * Update the version of an entity type bundle within a flow configuration.
     *
     * @param $entity_type
     * @param $bundle
     *
     * @throws \Exception
     */
    public function updateEntityTypeBundleVersion($entity_type, $bundle)
    {
        // Get active version.
        $active_version = Flow::getEntityTypeVersion($entity_type, $bundle);

        // Get version from config.
        $config_key = $entity_type.'-'.$bundle;
        $flow_config = \Drupal::service('config.factory')->getEditable('cms_content_sync.flow.'.$this->id());
        $config_version = $flow_config->get('sync_entities.'.$config_key.'.version');

        // Only update if required.
        if ($active_version != $config_version) {
            $default = self::getDefaultFieldConfigForEntityType($entity_type, $bundle, $this->sync_entities);
            foreach ($default as $id => $config) {
                $flow_config->set('sync_entities.'.$id, $config);
            }
            $flow_config->set('sync_entities.'.$config_key.'.version', $active_version);
            $flow_config->save();
            \Drupal::messenger()->addMessage('Content Sync - Flow: '.$this->label().' has been updated. Ensure to export the new configuration to the sync core.');
        }
    }

    /**
     * @param $type
     * @param $bundle
     * @param null $existing
     * @param null $field
     *
     * @return array
     */
    public static function getDefaultFieldConfigForEntityType($type, $bundle, $existing = null, $field = null)
    {
        if ($field) {
            $field_default_values = [
                'export' => null,
                'import' => null,
            ];

            $entity_type = \Drupal::entityTypeManager()->getDefinition($type);
            // @todo Should be gotten from the Entity Type Handler instead.
            $forbidden_fields = [
                // These are not relevant or misleading when synchronized.
                'revision_default',
                'revision_translation_affected',
                'content_translation_outdated',
                // Field collections.
                'host_type',
                // Files.
                'uri',
                'filemime',
                'filesize',
                // Media.
                'thumbnail',
                // Taxonomy.
                'parent',
                // These are standard fields defined by the Flow
                // Entity type that entities may not override (otherwise
                // these fields will collide with CMS Content Sync functionality)
                $entity_type->getKey('bundle'),
                $entity_type->getKey('id'),
                $entity_type->getKey('uuid'),
                $entity_type->getKey('label'),
                $entity_type->getKey('revision'),
            ];
            $pools = Pool::getAll();
            if (count($pools)) {
                $reserved = reset($pools)
                    ->getClient()
                    ->getReservedPropertyNames();
                $forbidden_fields = array_merge($forbidden_fields, $reserved);
            }

            if (false !== in_array($field, $forbidden_fields)) {
                $field_default_values['handler'] = 'ignore';
                $field_default_values['export'] = PushIntent::PUSH_DISABLED;
                $field_default_values['import'] = PullIntent::PULL_DISABLED;

                return $field_default_values;
            }

            $field_handler_service = \Drupal::service('plugin.manager.cms_content_sync_field_handler');
            $field_definition = \Drupal::service('entity_field.manager')->getFieldDefinitions($type, $bundle)[$field];

            $field_handlers = $field_handler_service->getHandlerOptions($type, $bundle, $field, $field_definition, true);
            if (empty($field_handlers)) {
                throw new \Exception('Unsupported field type '.$field_definition->getType().' for field '.$type.'.'.$bundle.'.'.$field);
            }
            reset($field_handlers);
            $handler_id = empty($field_default_values['handler']) ? key($field_handlers) : $field_default_values['handler'];

            $field_default_values['handler'] = $handler_id;
            $field_default_values['export'] = PushIntent::PUSH_AUTOMATICALLY;
            $field_default_values['import'] = PullIntent::PULL_AUTOMATICALLY;

            $handler = $field_handler_service->createInstance($handler_id, [
                'entity_type_name' => $type,
                'bundle_name' => $bundle,
                'field_name' => $field,
                'field_definition' => $field_definition,
                'settings' => $field_default_values,
                'sync' => null,
            ]);

            $advanced_settings = $handler->getHandlerSettings($field_default_values);
            if (count($advanced_settings)) {
                foreach ($advanced_settings as $name => $element) {
                    $field_default_values['handler_settings'][$name] = $element['#default_value'];
                }
            }

            return $field_default_values;
        }

        $entityTypeManager = \Drupal::service('entity_type.manager');
        $type = $entityTypeManager->getDefinition($type, false);

        $field_definition = $type ? \Drupal::service('entity_field.manager')->getFieldDefinitions($type, $bundle) : false;

        $result = [];

        if ($field_definition) {
            foreach ($field_definition as $key => $field) {
                $field_id = $type.'-'.$bundle.'-'.$key;
                if ($existing && isset($existing[$field_id])) {
                    continue;
                }
                $result[$field_id] = self::getDefaultFieldConfigForEntityType($type, $bundle, null, $key);
            }
        }

        return $result;
    }

    /**
     * Get the config for the given entity type or all entity types.
     *
     * @param $entity_type
     * @param $entity_bundle
     * @param bool $used_only
     *                        Return only the configs where a handler is set
     *
     * @return array
     */
    public function getEntityTypeConfig($entity_type = null, $entity_bundle = null, $used_only = false)
    {
        $entity_types = $this->sync_entities;

        $result = [];

        foreach ($entity_types as $id => &$type) {
            // Ignore field definitions.
            if (1 != substr_count($id, '-')) {
                continue;
            }

            if ($used_only && self::HANDLER_IGNORE == $type['handler']) {
                continue;
            }

            preg_match('/^(.+)-(.+)$/', $id, $matches);

            $entity_type_name = $matches[1];
            $bundle_name = $matches[2];

            if ($entity_type && $entity_type_name != $entity_type) {
                continue;
            }
            if ($entity_bundle && $bundle_name != $entity_bundle) {
                continue;
            }

            // If this is called before being saved, we want to have version etc.
            // available still.
            if (empty($type['version'])) {
                $type['version'] = Flow::getEntityTypeVersion($entity_type_name, $bundle_name);
                $type['entity_type_name'] = $entity_type_name;
                $type['bundle_name'] = $bundle_name;
            }

            if ($entity_type && $entity_bundle) {
                return $type;
            }

            $result[$id] = $type;
        }

        return $result;
    }

    /**
     * The the entity type handler for the given config.
     *
     * @param $config
     *   {@see Flow::getEntityTypeConfig()}
     *
     * @return \Drupal\cms_content_sync\Plugin\EntityHandlerInterface
     */
    public function getEntityTypeHandler($config)
    {
        $entityPluginManager = \Drupal::service('plugin.manager.cms_content_sync_entity_handler');

        return $entityPluginManager->createInstance(
            $config['handler'],
            [
                'entity_type_name' => $config['entity_type_name'],
                'bundle_name' => $config['bundle_name'],
                'settings' => $config,
                'sync' => $this,
            ]
        );
    }

    /**
     * Get the correct field handler instance for this entity type and field
     * config.
     *
     * @param $entity_type_name
     * @param $bundle_name
     * @param $field_name
     *
     * @return \Drupal\cms_content_sync\Plugin\FieldHandlerInterface
     */
    public function getFieldHandler($entity_type_name, $bundle_name, $field_name)
    {
        $fieldPluginManager = \Drupal::service('plugin.manager.cms_content_sync_field_handler');

        $key = $entity_type_name.'-'.$bundle_name.'-'.$field_name;
        if (empty($this->sync_entities[$key])) {
            return null;
        }

        if (self::HANDLER_IGNORE == $this->sync_entities[$key]['handler']) {
            return null;
        }

        $entityFieldManager = \Drupal::service('entity_field.manager');
        $field_definition = $entityFieldManager->getFieldDefinitions($entity_type_name, $bundle_name)[$field_name];

        return $fieldPluginManager->createInstance(
            $this->sync_entities[$key]['handler'],
            [
                'entity_type_name' => $entity_type_name,
                'bundle_name' => $bundle_name,
                'field_name' => $field_name,
                'field_definition' => $field_definition,
                'settings' => $this->sync_entities[$key],
                'sync' => $this,
            ]
        );
    }

    /**
     * Get the settings for the given field.
     *
     * @param $entity_type_name
     * @param $bundle_name
     * @param $field_name
     *
     * @return array
     */
    public function getFieldHandlerConfig($entity_type_name, $bundle_name, $field_name)
    {
        $key = $entity_type_name.'-'.$bundle_name.'-'.$field_name;
        if (!isset($this->sync_entities[$key])) {
            return null;
        }

        return $this->sync_entities[$key];
    }

    /**
     * Get the preview type.
     *
     * @param $entity_type_name
     * @param $bundle_name
     *
     * @return string
     */
    public function getPreviewType($entity_type_name, $bundle_name)
    {
        $previews_enabled = ContentSyncSettings::getInstance()->isPreviewEnabled();
        if (!$previews_enabled) {
            return Flow::PREVIEW_DISABLED;
        }

        $key = $entity_type_name.'-'.$bundle_name;
        $settings = $this->sync_entities[$key];
        if (empty($settings['preview'])) {
            return Flow::PREVIEW_DISABLED;
        }

        return $settings['preview'];
    }

    /**
     * Return all entity type configs with push enabled.
     *
     * @param $push_type
     * @param null|mixed $entity_type
     *
     * @return array
     */
    public function getPushedEntityTypes($push_type = null, $entity_type = null)
    {
        $pushed_entity_types = [];
        $entity_types = $this->getEntityTypeConfig();

        foreach ($entity_types as $key => $entity_type) {
            if ($entity_type && $key !== $entity_type) {
                continue;
            }

            if (is_null($push_type) && PushIntent::PUSH_DISABLED != $entity_type['export']) {
                $pushed_entity_types[$key] = $entity_type;
            } elseif ($entity_type['export'] == $push_type) {
                $pushed_entity_types[$key] = $entity_type;
            }
        }

        return $pushed_entity_types;
    }

    /**
     * Return all entity type configs with pull enabled.
     *
     * @param $pull_type
     * @param null|mixed $entity_type
     *
     * @return array
     */
    public function getEntityTypesToPull($pull_type = null, $entity_type = null)
    {
        $pulled_entity_types = [];
        $entity_types = $this->getEntityTypeConfig();

        foreach ($entity_types as $key => $entity_type) {
            if (is_null($pull_type) && PullIntent::PULL_DISABLED != $entity_type['import']) {
                $pulled_entity_types[$key] = $entity_type;
            } elseif ($entity_type['import'] == $pull_type) {
                $pulled_entity_types[$key] = $entity_type;
            }
        }

        return $pulled_entity_types;
    }

    /**
     * Create a flow configuration programmatically.
     *
     * @param $flow_name
     * @param string $flow_id
     * @param bool   $status
     * @param array  $dependencies
     * @param $configurations
     * @param bool $force_update
     *
     * @return mixed|string
     */
    public static function createFlow($flow_name, $flow_id = '', $status = true, $dependencies = [], $configurations, $force_update = false)
    {
        $flows = Flow::getAll(false);

        // If no flow_id is given, create one.
        if (empty($flow_id)) {
            $flow_id = strtolower($flow_name);
            $flow_id = preg_replace('@[^a-z0-9_]+@', '_', $flow_id);
        }

        if (!$force_update && array_key_exists($flow_id, $flows)) {
            \Drupal::messenger()->addMessage('A flow with the machine name '.$flow_id.' does already exist. Therefor the creation has been skipped.', 'warning');
        } else {
            $uuid_service = \Drupal::service('uuid');
            $language_manager = \Drupal::service('language_manager');
            $default_language = $language_manager->getDefaultLanguage();
            $config = [
                'dependencies' => $dependencies,
            ];

            $flow_config = \Drupal::service('config.factory')->getEditable('cms_content_sync.flow.'.$flow_id);
            // Setup base configurations.
            $flow_config
                ->set('uuid', $uuid_service->generate())
                ->set('langcode', $default_language->getId())
                ->set('status', true)
                ->set('id', $flow_id)
                ->set('name', $flow_name)
                ->set('config', $config)
                ->set('sync_entities', []);

            // Configure entity types.
            foreach ($configurations as $entity_type_key => $bundles) {
                foreach ($bundles as $bundle_key => $bundle) {
                    $entityPluginManager = \Drupal::service('plugin.manager.cms_content_sync_entity_handler');
                    $entity_handler = $entityPluginManager->getHandlerOptions($entity_type_key, $bundle_key);
                    $entity_handler = reset($entity_handler);

                    // Set configurations.
                    $flow_config->set('sync_entities.'.$entity_type_key.'-'.$bundle_key, [
                        'handler' => $entity_handler['id'],
                        'entity_type_name' => $entity_type_key,
                        'bundle_name' => $bundle_key,
                        'version' => Flow::getEntityTypeVersion($entity_type_key, $bundle_key),
                        'export' => $bundle['push_configuration']['behavior'] ?? PushIntent::PUSH_DISABLED,
                        'export_deletion_settings' => [
                            'export_deletion' => $bundle['push_configuration']['export_deletion_settings'] ?? '',
                        ],
                        'export_pools' => $bundle['push_configuration']['export_pools'] ?? [],
                        'import' => $bundle['import_configuration']['behavior'] ?? PullIntent::PULL_DISABLED,
                        'import_deletion_settings' => [
                            'import_deletion' => $bundle['import_configuration']['import_deletion'] ?? 0,
                            'allow_local_deletion_of_import' => $bundle['import_configuration']['allow_local_deletion_of_import'] ?? 0,
                        ],
                        'import_updates' => $bundle['import_configuration']['import_updates'] ?? PullIntent::PULL_UPDATE_FORCE,
                        'import_pools' => $bundle['import_configuration']['import_pools'] ?? [],
                        'pool_export_widget_type' => 'checkboxes',
                        'preview' => 'table',
                    ]);

                    /**
                     * @var \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
                     */
                    $entityFieldManager = \Drupal::service('entity_field.manager');
                    $fields = $entityFieldManager->getFieldDefinitions($entity_type_key, $bundle_key);
                    foreach (Flow::getDefaultFieldConfigForEntityType($entity_type_key, $bundle_key) as $field_id => $field_config) {
                        if (!empty($bundle['tags'])) {
                            list(, , $field_name) = explode('-', $field_id);

                            $field = $fields[$field_name];
                            if ($field && 'entity_reference' == $field->getType() && 'taxonomy_term' == $field->getSetting('target_type')) {
                                $bundles = $field->getSetting('target_bundles');
                                if (!$bundles) {
                                    $field_settings = $field->getSettings();
                                    $bundles = $field_settings['handler_settings']['target_bundles'];
                                }
                                if (is_array($bundles)) {
                                    foreach ($bundle['tags'] as $tag) {
                                        if (in_array($tag->bundle(), $bundles)) {
                                            $field_config['handler_settings']['subscribe_only_to'][] = [
                                                'type' => 'taxonomy_term',
                                                'bundle' => $tag->bundle(),
                                                'uuid' => $tag->uuid(),
                                            ];
                                        }
                                    }
                                }
                            }
                        }

                        $flow_config->set('sync_entities.'.$field_id, $field_config);
                    }
                }
            }
            $flow_config->save();
        }

        return $flow_id;
    }

    public function useV2()
    {
        return self::V2_STATUS_ACTIVE === $this->getV2Status() || Migration::useV2();
    }

    public function v2Ready()
    {
        $status = $this->getV2Status();

        return self::V2_STATUS_NONE != $status;
    }

    public function getV2Status()
    {
        return Migration::getFlowStatus($this);
    }
}
