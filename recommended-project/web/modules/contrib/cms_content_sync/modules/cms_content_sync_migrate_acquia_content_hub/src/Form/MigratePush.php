<?php

namespace Drupal\cms_content_sync_migrate_acquia_content_hub\Form;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\acquia_contenthub\EntityManager;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\cms_content_sync\PushIntent;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * Content Sync advanced debug form.
 */
class MigratePush extends MigrationBase {

  /**
   *
   */
  public function __construct(EntityManager $acquia_entity_manager, EntityTypeBundleInfoInterface $entity_type_bundle_info, FieldTypePluginManagerInterface $field_type_plugin_manager, ConfigFactoryInterface $config_factory, ModuleHandler $moduleHandler, EntityTypeManager $entity_type_manager) {
    parent::__construct($acquia_entity_manager, $entity_type_bundle_info, $field_type_plugin_manager, $config_factory, $moduleHandler, $entity_type_manager);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'cms_content_sync_migrate_acquia_content_hub.migrate_export';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $this->migrationType = 'push';
    $form = parent::buildForm($form, $form_state);

    $url = Url::fromUri('https://edge-box.atlassian.net/wiki/spaces/SUP/pages/137232742/Export+and+Import+settings');
    $link = Link::fromTextAndUrl(t('here'), $url);
    $link = $link->toRenderable();
    $link['#attributes'] = ['class' => ['external']];
    $link = render($link);

    $form['node_push_behavior'] = [
      '#title' => $this->t('Node push behavior'),
      '#description' => $this->t('This configuration allows to define if Nodes should be pushed automatically ("All") or manually ("Manually"). Further information about push behaviors could be found @link.', [
        '@link' => $link,
      ]),
      '#type' => 'select',
      '#options' => [
        PushIntent::PUSH_AUTOMATICALLY => $this->t('Automatically'),
        PushIntent::PUSH_MANUALLY => $this->t('Manually'),
      ],
      '#default_value' => PushIntent::PUSH_AUTOMATICALLY,
    ];

    $form['#attached']['library'][] = 'cms_content_sync_migrate_acquia_content_hub/migrate-form';

    return $form;
  }

  /**
   * @param string $pool_id
   * @param string $node_push_behavior
   * @param string $pull_updates_behavior
   *
   * @param bool $force_update
   *
   * @return array|string
   */
  public static function createFlow($pool_id, $node_push_behavior, $pull_updates_behavior, $force_update = FALSE, $override = NULL) {
    // Get Acquia Content Hub configurations.
    $content_hub_configrations = MigratePush::getAcquiaContentHubConfigrations();

    // Create a new flow based on the given Acquia Content Hub configurations.
    foreach ($content_hub_configrations as $entity_type_key => $content_hub_configration) {

      // If no bundles are configured, the entity type can be skipped.
      if (!in_array(TRUE, $content_hub_configration)) {
        continue;
      }

      foreach ($content_hub_configration as $bundle_key => $bundle) {
        if ($bundle) {
          // @todo More Handler options?
          // General configurations.
          $configurations[$entity_type_key][$bundle_key]['push_configuration'] = [
            'export_deletion_settings' => TRUE,
          ];

          $configurations[$entity_type_key][$bundle_key]['push_configuration']['export_pools'] = [];

          $usage = $entity_type_key == 'node' ? Pool::POOL_USAGE_ALLOW : Pool::POOL_USAGE_FORCE;
          $configurations[$entity_type_key][$bundle_key]['push_configuration']['export_pools'][$pool_id] = $usage;

          // Export everything beside nodes as dependencies, but allow overrides.
          if (isset($override[$entity_type_key][$bundle_key]['push_configuration']['behavior'])) {
            $configurations[$entity_type_key][$bundle_key]['push_configuration']['behavior'] = $override[$entity_type_key][$bundle_key]['push_configuration']['behavior'];
          }
          elseif ($entity_type_key == 'node') {
            $configurations[$entity_type_key][$bundle_key]['push_configuration']['behavior'] = $node_push_behavior;
          }
          else {
            $configurations[$entity_type_key][$bundle_key]['push_configuration']['behavior'] = PushIntent::PUSH_AS_DEPENDENCY;
          }
        }
      }
    }

    if (!empty($configurations)) {
      \Drupal::messenger()->addMessage('The pushing flow has been created, please review your settings.');
      return [
        'flow_id' => Flow::createFlow('Push', 'push_migrated', TRUE, [
          'config' => [
            'cms_content_sync.pool.' . $pool_id,
          ],
        ], $configurations, $force_update),
        'flow_configuration' => $configurations,
        'type' => 'push',
      ];
    }
    else {
      \Drupal::messenger()->addMessage('Content Sync Pushing Flow has not been created.', 'warning');
      return '';
    }
  }

  /**
   * Get Entity Type configurations of the Acquia Content Hub.
   *
   * @return array
   */
  public static function getAcquiaContentHubConfigrations() {
    $entity_types = \Drupal::service('acquia_contenthub.entity_manager')->getAllowedEntityTypes();
    $content_hub_configurations = [];
    foreach ($entity_types as $entity_type_key => $entity_type) {
      $contenthub_entity_config_id = \Drupal::service('acquia_contenthub.entity_manager')->getContentHubEntityTypeConfigurationEntity($entity_type_key);
      foreach ($entity_type as $bundle_key => $bundle) {
        $content_hub_configurations[$entity_type_key][$bundle_key] = $contenthub_entity_config_id ? $contenthub_entity_config_id->isEnableIndex($bundle_key) : FALSE;
      }

    }
    return $content_hub_configurations;
  }

}
