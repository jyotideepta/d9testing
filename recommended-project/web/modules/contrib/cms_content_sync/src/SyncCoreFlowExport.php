<?php

namespace Drupal\cms_content_sync;

use Drupal\cms_content_sync\Controller\ContentSyncSettings;
use Drupal\cms_content_sync\Controller\Migration;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\cms_content_sync\Event\BeforeEntityTypeExport;
use Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager;
use Drupal\cms_content_sync\SyncCoreInterface\SyncCoreFactory;
use Drupal\Core\Serialization\Yaml;
use EdgeBox\SyncCore\Interfaces\ISyncCore;

/**
 * Class SyncCoreFlowExport used to export the Synchronization config to the Sync
 * Core backend.
 */
class SyncCoreFlowExport extends SyncCoreExport
{
    protected $subsequent = false;

    /**
     * @var \Drupal\cms_content_sync\Entity\Flow
     */
    protected $flow;

    /**
     * Sync Core Config constructor.
     *
     * @param \Drupal\cms_content_sync\Entity\Flow $flow
     *                                                         The flow this exporter is used for
     * @param mixed                                $subsequent
     */
    public function __construct(Flow $flow, $subsequent = false)
    {
        $pools = $flow->getUsedPools();
        $first = reset($pools);
        parent::__construct($first->getClient());

        $this->flow = $flow;
        $this->subsequent = $subsequent;
    }

    public static function deleteUnusedFlows()
    {
        if (Migration::alwaysUseV2()) {
            $keep_flows = [];
            // Get all active Flows and add them to the "keep list".
            foreach (Flow::getAll() as $flow) {
                $keep_flows[] = $flow->id;
            }

            $core = SyncCoreFactory::getSyncCoreV2();
            // We are deleting all that are not in the "keep list". This is the
            // most reliable way to delete all unwanted configuration as we
            // may not always be informed about changes of Flows (e.g. when
            // customers use a database dump for deployment or setup), and we
            // want the Flow status to be in sync to avoid unnecessary requests
            // and failures.
            $core->getConfigurationService()->deleteFlows($keep_flows);
        }
    }

    /**
     * Create all entity types, connections and synchronizations as required.
     *
     * @param bool $force
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \EdgeBox\SyncCore\Exception\SyncCoreException
     *
     * @return \EdgeBox\SyncCore\Interfaces\IBatch
     */
    public function prepareBatch($force = false)
    {
        $pool_configurations = [];

        $batch = $this
            ->client
            ->batch();

        $flow_definition = $this
            ->client
            ->getConfigurationService()
            ->defineFlow(
                $this->flow->id,
                $this->flow->label(),
                Yaml::encode(\Drupal::service('config.storage')->read('cms_content_sync.flow.'.$this->flow->id()))
            )
            ->addToBatch($batch);

        $this
            ->addConfiguration($flow_definition, $pool_configurations, $batch, false, $force);

        // v1 of the Sync Core can't handle overlapping configuration independently,
        // so we need to add the configuration from Flows that overlap with the
        // Flow that is to be exported now.
        if (!$this->client->featureEnabled(ISyncCore::FEATURE_INDEPENDENT_FLOW_CONFIG)) {
            foreach (Flow::getAll() as $id => $flow) {
                if ($this->flow->id === $id) {
                    continue;
                }

                $sub = new SyncCoreFlowExport($flow);
                $sub->addConfiguration($flow_definition, $pool_configurations, $batch, true, $force);
            }
        }

        // Adding these must be done last so that the entity types are always
        // defined before being used. Otherwise the Sync Core will throw an exception.
        foreach ($pool_configurations as $pool_id => $operations) {
            /**
             * @var \EdgeBox\SyncCore\Interfaces\IBatchOperation $pool
             */
            $pool = $operations['pool_definition'];
            $pool->addToBatch($batch);
        }

        return $batch;
    }

    /**
     * Create all entity types, connections and synchronizations as required.
     *
     * @param \EdgeBox\SyncCore\Interfaces\Configuration\IDefineFlow $flow_definition
     * @param $pool_configurations
     * @param \EdgeBox\SyncCore\Interfaces\IBatch $batch
     * @param bool                                $extend_only
     * @param bool                                $force
     *
     * @throws \EdgeBox\SyncCore\Exception\SyncCoreException
     */
    public function addConfiguration($flow_definition, &$pool_configurations, $batch, $extend_only = false, $force = false)
    {
        // Ignore disabled flows at export.
        if (!$this->flow->get('status')) {
            return;
        }

        $enable_preview = ContentSyncSettings::getInstance()->isPreviewEnabled();

        $entity_types = $this->flow->sync_entities;

        $pools = Pool::getAll();

        $export_pools = [];

        foreach ($this->flow->getEntityTypeConfig() as $id => $type) {
            $entity_type_name = $type['entity_type_name'];
            $bundle_name = $type['bundle_name'];
            $version = $type['version'];

            if (Flow::HANDLER_IGNORE == $type['handler']) {
                continue;
            }

            $current = Flow::getEntityTypeVersion($entity_type_name, $bundle_name);
            if ($current !== $version) {
                throw new \Exception("Entity type {$entity_type_name}.{$bundle_name} was changed without updating Flow {$this->flow->id}. Please re-save that Flow first to apply the latest entity type changes.");
            }

            $handler = $this->flow->getEntityTypeHandler($type);

            $entity_type_pools = [];
            if (isset($type['import_pools'])) {
                foreach ($type['import_pools'] as $pool_id => $state) {
                    if (!isset($entity_type_pools[$pool_id])) {
                        $entity_type_pools[$pool_id] = [];
                    }

                    if (PullIntent::PULL_DISABLED == $type['import']) {
                        $entity_type_pools[$pool_id]['import'] = Pool::POOL_USAGE_FORBID;

                        continue;
                    }

                    $entity_type_pools[$pool_id]['import'] = $state;
                }
            }

            if (isset($type['export_pools'])) {
                foreach ($type['export_pools'] as $pool_id => $state) {
                    if (!isset($entity_type_pools[$pool_id])) {
                        $entity_type_pools[$pool_id] = [];
                    }

                    if (PushIntent::PUSH_DISABLED == $type['export']) {
                        $entity_type_pools[$pool_id]['export'] = Pool::POOL_USAGE_FORBID;

                        continue;
                    }

                    $entity_type_pools[$pool_id]['export'] = $state;
                }
            }

            foreach ($entity_type_pools as $pool_id => $definition) {
                if (empty($pools[$pool_id])) {
                    continue;
                }

                $pool = $pools[$pool_id];

                $export = null;
                $import = null;

                if (isset($definition['export'])) {
                    $export = $definition['export'];
                }

                if (isset($definition['import'])) {
                    $import = $definition['import'];
                }

                if ((!$export || Pool::POOL_USAGE_FORBID == $export) && (!$import || Pool::POOL_USAGE_FORBID == $import)) {
                    continue;
                }

                if (!in_array($pool, $export_pools)) {
                    $export_pools[] = $pool;
                }

                $entity_type_id_without_version = $entity_type_name.'-'.$bundle_name;

                if ($extend_only) {
                    if (!isset($pool_configurations[$pool_id])) {
                        continue;
                    }
                }

                $pull_condition = [];

                if (EntityHandlerPluginManager::isEntityTypeFieldable($entity_type_name)) {
                    $entityFieldManager = \Drupal::service('entity_field.manager');
                    /** @var \Drupal\Core\Field\FieldDefinitionInterface[] $fields */
                    $fields = $entityFieldManager->getFieldDefinitions($entity_type_name, $bundle_name);

                    $forbidden = $handler->getForbiddenFields();

                    foreach ($fields as $key => $field) {
                        if (!isset($entity_types[$id.'-'.$key])) {
                            continue;
                        }

                        if (in_array($key, $forbidden)) {
                            continue;
                        }
                        if (!empty($entity_types[$id.'-'.$key]['handler_settings']['subscribe_only_to'])) {
                            $allowed = [];

                            foreach ($entity_types[$id.'-'.$key]['handler_settings']['subscribe_only_to'] as $ref) {
                                $allowed[] = $ref['uuid'];
                            }

                            $pull_condition[$key] = $allowed;
                        }
                    }
                }

                if ($extend_only) {
                    if (isset($pool_configurations[$pool_id]['entity_types'][$entity_type_id_without_version])) {
                        if (Pool::POOL_USAGE_FORBID != $import && PullIntent::PULL_DISABLED != $type['import']) {
                            /**
                             * @var \EdgeBox\SyncCore\Interfaces\Configuration\IFlowPullConfiguration $pull_configuration
                             */
                            $pull_configuration = $pool_configurations[$pool_id]['entity_types'][$entity_type_id_without_version];

                            $override = $pull_configuration
                                ->configureOverride($this->flow->id)
                                ->manually(PullIntent::PULL_MANUALLY == $type['import'])
                                ->asDependency(PullIntent::PULL_AS_DEPENDENCY == $type['import'])
                                ->pullDeletions(boolval($type['import_deletion_settings']['import_deletion']));

                            foreach ($pull_condition as $property => $allowed_entity_ids) {
                                $override
                                    ->ifTaggedWith($property, $allowed_entity_ids);
                            }
                        }

                        continue;
                    }
                }

                $entity_type_label = \Drupal::service('entity_type.bundle.info')->getAllBundleInfo()[$entity_type_name][$bundle_name]['label'];
                $entity_type = $this
                    ->client
                    ->getConfigurationService()
                    ->defineEntityType($pool_id, $entity_type_name, $bundle_name, $version, $entity_type_label)
                    ->isTranslatable(true)
                    ->addObjectProperty('metadata', 'Metadata')
                    ->addObjectProperty('menu_items', 'Menu items', true);

                if (EntityHandlerPluginManager::isEntityTypeFieldable($entity_type_name)) {
                    $entityFieldManager = \Drupal::service('entity_field.manager');
                    /** @var \Drupal\Core\Field\FieldDefinitionInterface[] $fields */
                    $fields = $entityFieldManager->getFieldDefinitions($entity_type_name, $bundle_name);

                    $forbidden = $handler->getForbiddenFields();

                    $added = [];
                    foreach ($fields as $key => $field) {
                        if (!isset($entity_types[$id.'-'.$key])) {
                            continue;
                        }

                        if (in_array($key, $forbidden)) {
                            continue;
                        }

                        $field_handler = $this->flow->getFieldHandler($entity_type_name, $bundle_name, $key);
                        if (!$field_handler) {
                            continue;
                        }
                        $field_handler->definePropertyAtType($entity_type);

                        $added[] = $key;
                    }

                    if (!in_array('created', $added)) {
                        if ($this->flow->useV2()) {
                            $entity_type->addObjectProperty('created', 'Created', false, false);
                        } else {
                            $entity_type->addIntegerProperty('created', 'Created', false, false);
                        }
                    }
                    if (!in_array('changed', $added)) {
                        if ($this->flow->useV2()) {
                            $entity_type->addObjectProperty('changed', 'Changed', false, false);
                        } else {
                            $entity_type->addIntegerProperty('changed', 'Changed', false, false);
                        }
                    }
                }

                // Remote sites must use the same entity type handler otherwise sync
                // will fail- at least for these properties or it will not work at all.
                $handler->updateEntityTypeDefinition($entity_type);

                // Dispatch EntityTypeExport event to give other modules the possibility
                // to adjust the entity type definition and add custom fields.
                \Drupal::service('event_dispatcher')->dispatch(
                    BeforeEntityTypeExport::EVENT_NAME,
                    new BeforeEntityTypeExport($entity_type_name, $bundle_name, $entity_type)
                );

                // Create the entity type.
                $entity_type->addToBatch($batch);

                /**
                 * @var \EdgeBox\SyncCore\Interfaces\Configuration\IDefinePoolForFlow $pool_definition
                 */
                if (isset($pool_configurations[$pool_id])) {
                    // If the given entity type has added before, we don't add it a second time.
                    $pool_definition = $pool_configurations[$pool_id]['pool_definition'];
                } else {
                    $pool_definition = $flow_definition
                        ->usePool($pool_id);

                    $pool_configurations[$pool_id] = [
                        'pool_definition' => $pool_definition,
                        'entity_types' => [],
                    ];
                }

                $pool_definition
                    ->useEntityType($entity_type);

                if ($extend_only) {
                    continue;
                }

                // Create a synchronization from the pool to the preview connection.
                if ($enable_preview) {
                    $pool_definition
                        ->enablePreview($entity_type);
                }

                if (Pool::POOL_USAGE_FORBID != $export && PushIntent::PUSH_DISABLED != $type['export']) {
                    $push_config = $pool_definition
                        ->enablePush($entity_type);
                    if ($push_config) {
                        $push_config
                            ->manually(PushIntent::PUSH_MANUALLY == $type['export'])
                            ->asDependency(PushIntent::PUSH_AS_DEPENDENCY == $type['export'])
                            ->pushDeletions(boolval($type['export_deletion_settings']['export_deletion']));
                    }
                }

                if (Pool::POOL_USAGE_FORBID != $import && PullIntent::PULL_DISABLED != $type['import']) {
                    $pull_configuration = $pool_definition
                        ->enablePull($entity_type)
                        ->manually(PullIntent::PULL_MANUALLY == $type['import'])
                        ->asDependency(PullIntent::PULL_AS_DEPENDENCY == $type['import'])
                        ->pullDeletions(boolval($type['import_deletion_settings']['import_deletion']))
                        ->addToBatch($batch);

                    $pool_configurations[$pool_id]['entity_types'][$entity_type_id_without_version] = $pull_configuration;

                    foreach ($pull_condition as $property => $allowed_entity_ids) {
                        $pull_configuration
                            ->ifTaggedWith($property, $allowed_entity_ids);
                    }
                }
            }
        }

        if ($extend_only) {
            return;
        }

        // Always export required pools as well to prevent any potential issues.
        // @todo Optimize when called via Drush to not send the same pool requests for multiple Flows.
        $subsequent = $this->subsequent;
        foreach ($export_pools as $pool) {
            $exporter = new SyncCorePoolExport($pool);

            $batch->prepend(
                $exporter->prepareBatch($subsequent, $force)
            );

            $subsequent = true;
        }
    }
}
