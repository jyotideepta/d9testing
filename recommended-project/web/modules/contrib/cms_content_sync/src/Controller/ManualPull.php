<?php

namespace Drupal\cms_content_sync\Controller;

use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\SyncCoreInterface\DrupalApplication;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;

/**
 * Provides a listing of Flow.
 */
class ManualPull extends ControllerBase
{
    /**
     * Ensure that the pull tab is just show if a flow exists which contains
     * and entity type that has its pull set to "manual".
     */
    public function access()
    {
        $flows = Flow::getAll();
        $manually_pulled_entity_types = [];
        foreach ($flows as $flow) {
            if (!empty($flow->getEntityTypesToPull(PullIntent::PULL_MANUALLY))) {
                $manually_pulled_entity_types[$flow->id()] = $flow->getEntityTypesToPull(PullIntent::PULL_MANUALLY);
            }
        }

        return AccessResult::allowedIf(!empty($manually_pulled_entity_types))
            ->addCacheableDependency($flows);
    }

    /**
     * Render the content synchronization Angular frontend.
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \EdgeBox\SyncCore\Exception\SyncCoreException
     *
     * @return array
     */
    public function content()
    {
        $settings = ContentSyncSettings::getInstance();

        if (Migration::alwaysUseV2() || 'yes' === \Drupal::request()->query->get('v2')) {
            $embed = Embed::create(\Drupal::getContainer());

            return $embed->pullDashboard();
        }

        $config = [
            'siteUrl' => $settings->getSiteBaseUrl(),
            'pools' => [],
            'flows' => [],
            'entity_types' => [],
        ];

        $pools = Pool::getAll();

        $cloud = _cms_content_sync_is_cloud_version() && $settings->isDirectSyncCoreAccessEnabled();
        $sync_core_settings = null;

        $connection_id = null;
        foreach (Flow::getAll() as $id => $flow) {
            $config['flows'][$flow->id] = [
                'id' => $flow->id,
                'name' => $flow->name,
            ];

            foreach ($flow->getEntityTypeConfig() as $definition) {
                if (!$flow->canPullEntity($definition['entity_type_name'], $definition['bundle_name'], PullIntent::PULL_MANUALLY)) {
                    continue;
                }

                foreach ($flow->getEntityTypeConfig($definition['entity_type_name'], $definition['bundle_name'])['import_pools'] as $id => $option) {
                    if (Pool::POOL_USAGE_ALLOW != $option) {
                        continue;
                    }
                    $pool = $pools[$id];
                    $config['pools'][$pool->id] = [
                        'id' => $pool->id,
                        'label' => $pool->label,
                        'site_id' => DrupalApplication::get()->getSiteMachineName(),
                    ];

                    if (!$sync_core_settings) {
                        $sync_core_settings = $pool
                            ->getClient()
                            ->getSyndicationService()
                            ->configurePullDashboard();
                    }
                }

                $index = $definition['entity_type_name'].'.'.$definition['bundle_name'];
                if (!isset($config['entity_types'][$index])) {
                    // Get the entity type and bundle name.
                    $entity_type_storage = \Drupal::entityTypeManager()->getStorage($definition['entity_type_name']);
                    $entity_type = $entity_type_storage->getEntityType();
                    $entity_type_label = $entity_type->getLabel()->render();
                    $bundle_info = \Drupal::service('entity_type.bundle.info')->getBundleInfo($definition['entity_type_name']);
                    $bundle_label = $bundle_info[$definition['bundle_name']]['label'];

                    $config['entity_types'][$index] = [
                        'entity_type_name' => $definition['entity_type_name'],
                        'entity_type_label' => $entity_type_label,
                        'bundle_name' => $definition['bundle_name'],
                        'bundle_label' => $bundle_label,
                        'version' => $definition['version'],
                        'pools' => [],
                        'preview' => $definition['preview'] ?? Flow::PREVIEW_DISABLED,
                    ];
                } else {
                    if (Flow::PREVIEW_DISABLED == $config['entity_types'][$index]['preview'] || Flow::PREVIEW_TABLE != $definition['preview']) {
                        $config['entity_types'][$index]['preview'] = $definition['preview'] ?? Flow::PREVIEW_DISABLED;
                    }
                }

                foreach ($definition['import_pools'] as $id => $action) {
                    if (!isset($config['entity_types'][$index]['pools'][$id])
            || Pool::POOL_USAGE_FORCE == $action
            || Pool::POOL_USAGE_FORBID == $config['entity_types'][$index]['pools'][$id]) {
                        $config['entity_types'][$index]['pools'][$id] = $action;
                    }
                }
            }
        }

        // Provide additional conditions for "subscribe only to" filters.
        if ($cloud) {
            $entity_type_ids = [];

            /**
             * @var \Drupal\Core\Entity\EntityFieldManager $entityFieldManager
             */
            $entityFieldManager = \Drupal::service('entity_field.manager');

            foreach (Flow::getAll() as $flow) {
                foreach ($flow->getEntityTypeConfig() as $definition) {
                    if (!$flow->canPullEntity($definition['entity_type_name'], $definition['bundle_name'], PullIntent::PULL_MANUALLY)) {
                        continue;
                    }

                    foreach ($definition['import_pools'] as $pool_id => $behavior) {
                        if (Pool::POOL_USAGE_ALLOW != $behavior) {
                            continue;
                        }

                        if (isset($entity_type_ids[$pool_id][$definition['entity_type_name']][$definition['bundle_name']])) {
                            continue;
                        }

                        if (EntityHandlerPluginManager::isEntityTypeFieldable($definition['entity_type_name'])) {
                            /**
                             * @var \Drupal\Core\Field\FieldDefinitionInterface[] $fields
                             */
                            $fields = $entityFieldManager->getFieldDefinitions($definition['entity_type_name'], $definition['bundle_name']);
                            foreach ($fields as $key => $field) {
                                $field_config = $flow->getFieldHandlerConfig($definition['entity_type_name'], $definition['bundle_name'], $key);
                                if (empty($field_config)) {
                                    continue;
                                }

                                if (empty($field_config['handler_settings']['subscribe_only_to'])) {
                                    continue;
                                }

                                $allowed = [];

                                foreach ($field_config['handler_settings']['subscribe_only_to'] as $ref) {
                                    $allowed[] = $ref['uuid'];
                                }

                                $sync_core_settings->ifTaggedWith(
                                    $pool_id,
                                    $definition['entity_type_name'],
                                    $definition['bundle_name'],
                                    $key,
                                    $allowed
                                );
                            }
                        }

                        $entity_type_ids[$pool_id][$definition['entity_type_name']][$definition['bundle_name']] = true;
                    }
                }
            }
        }

        $config = array_merge($config, $sync_core_settings->getConfig());

        if (empty($config['entity_types'])) {
            \Drupal::messenger()->addMessage(t('There are no entity types to be pulled manually.'));
        }

        return [
            '#theme' => 'cms_content_sync_content_dashboard',
            '#configuration' => $config,
            '#attached' => ['library' => ['cms_content_sync/pull']],
        ];
    }
}
