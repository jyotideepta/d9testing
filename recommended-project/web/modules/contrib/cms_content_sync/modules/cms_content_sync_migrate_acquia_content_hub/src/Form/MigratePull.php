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
use Drupal\cms_content_sync\PullIntent;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * Migrate a Acquia Content Hub Filter to Content Sync.
 */
class MigratePull extends MigrationBase {

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
    return 'cms_content_sync_migrate_acquia_content_hub.migrate_pull';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $content_hub_filter_id = NULL) {
    // @todo Is it possible to load the argument within the constructer?
    $this->content_hub_filter_id = $content_hub_filter_id;
    $this->migrationType = 'pull';

    $content_hub_filter = $this->entityTypeManager
      ->getStorage('contenthub_filter')
      ->load($this->content_hub_filter_id);

    $this->content_hub_filter = $content_hub_filter;

    $publish_setting = $this->content_hub_filter->publish_setting;
    $from_date = $this->content_hub_filter->from_date;
    $to_date = $this->content_hub_filter->to_date;
    $sources = $this->content_hub_filter->source;
    $content_hub_entity_types = $content_hub_filter->getEntityTypes();
    $content_hub_bundles = $content_hub_filter->getBundles();

    if ($publish_setting != 'none') {
      \Drupal::messenger()->addMessage($this->t('Be aware that Content Sync does not support the Acquia Content Hub settings for: "Publish Setting" (Sets the Publish setting for this filter.), which is currently configured as "@value"', ['@value' => $publish_setting]), 'warning');
    }
    if ($from_date != '' || $to_date != '') {
      \Drupal::messenger()->addMessage($this->t('Be aware that Content Sync does not support the Acquia Content Hub settings for: "Date From" (Date starting from) | "Date To" (Date until), which are currently configured as Date From: "@date_from_value" and Date To: "@date_to_value"', [
        '@date_from_value' => $from_date,
        '@date_to_value' => $to_date,
      ]), 'warning');
    }
    if ($sources != '') {
      \Drupal::messenger()->addMessage($this->t('Be aware that Content Sync does not support the Acquia Content Hub settings for: "Sources" (Source origin site UUIDs, delimited by comma ",".), which is currently configured as @value', ['@value' => $sources]), 'warning');
    }
    if (empty($content_hub_entity_types)) {
      \Drupal::messenger()->addMessage($this->t('Since none entity types have been configured within this Acquia Content Hub filter, no entity types will be configured for this pull flow.'));
    }
    if (empty($content_hub_bundles)) {
      \Drupal::messenger()->addMessage($this->t('Since none bundles have been configured within this Acquia Content Hub filter, no bundles will be configured for this pull flow.'));
    }

    $form = parent::buildForm($form, $form_state);

    $url = Url::fromUri('https://edge-box.atlassian.net/wiki/spaces/SUP/pages/137232737/Update+behaviors');
    $link = Link::fromTextAndUrl(t('here'), $url);
    $link = $link->toRenderable();
    $link['#attributes'] = ['class' => ['external']];
    $link = render($link);

    $form['pull_updates_behavior'] = [
      '#title' => $this->t('Pull updates behavior'),
      '#description' => $this->t('This configuration allows to define the pull updates behaviors. Further information could be found @link.', [
        '@link' => $link,
      ]),
      '#type' => 'select',
      '#options' => [
        PullIntent::PULL_UPDATE_FORCE_AND_FORBID_EDITING => $this->t('Forbid local changes and update'),
        PullIntent::PULL_UPDATE_FORCE_UNLESS_OVERRIDDEN  => $this->t('Update unless overridden locally'),
        PullIntent::PULL_UPDATE_FORCE => $this->t('Dismiss local changes'),
        PullIntent::PULL_UPDATE_IGNORE  => $this->t('Ignore updates completely'),
      ],
      '#default_value' => PullIntent::PULL_UPDATE_FORCE_AND_FORBID_EDITING,
    ];

    return $form;
  }

  /**
   * Create the CMS Content Hub pull flow for the content hub filter.
   *
   * @param string $pool_id
   * @param string $node_push_behavior
   * @param string $pull_updates_behavior
   * @param \Drupal\acquia_contenthub_subscriber\Entity\ContentHubFilter $content_hub_filter
   * @param bool $force_update
   * @param array $override
   *
   * @return mixed|string
   */
  public static function createFlow($pool_id, $node_push_behavior, $pull_updates_behavior, $content_hub_filter, $force_update = FALSE, $override = NULL) {
    $configurations = [];

    // Since Acquia does not save the relation between entity types and bundles
    // we need to take care of the mapping.
    $content_hub_entity_types = $content_hub_filter->getEntityTypes();
    $content_hub_bundles = $content_hub_filter->getBundles();
    $tags = MigrationBase::getTermsFromFilter($content_hub_filter->tags);

    foreach ($content_hub_entity_types as $content_hub_entity_type) {
      $entity_type_bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo($content_hub_entity_type);

      foreach ($content_hub_bundles as $content_hub_bundle_key => $content_hub_bundle) {

        if (array_key_exists($content_hub_bundle, $entity_type_bundles)) {

          // General configurations.
          $configurations[$content_hub_entity_type][$content_hub_bundle]['import_configuration'] = [
            'import_deletion' => TRUE,
            'allow_local_deletion_of_import' => TRUE,
            'import_updates' => $pull_updates_behavior,
          ];

          // Pool configuration.
          $configurations[$content_hub_entity_type][$content_hub_bundle]['import_configuration']['import_pools'][$pool_id] = Pool::POOL_USAGE_FORCE;

          // Import everything beside nodes as dependencies but allow overrides.
          if (isset($override[$content_hub_entity_type][$content_hub_bundle]['import_configuration']['behavior'])) {
            $configurations[$content_hub_entity_type][$content_hub_bundle]['import_configuration']['behavior'] = $override[$content_hub_entity_type][$content_hub_bundle]['import_configuration']['behavior'];
          }
          elseif ($content_hub_entity_type == 'node') {
            $configurations[$content_hub_entity_type][$content_hub_bundle]['import_configuration']['behavior'] = PullIntent::PULL_AUTOMATICALLY;
            if (!empty($tags)) {
              $configurations[$content_hub_entity_type][$content_hub_bundle]['tags'] = $tags;
            }
          }
          else {
            $configurations[$content_hub_entity_type][$content_hub_bundle]['import_configuration']['behavior'] = PullIntent::PULL_AS_DEPENDENCY;
          }
        }
      }
    }

    \Drupal::messenger()->addMessage('The pull flow has been created, please review your settings.');

    return [
      'flow_id' => Flow::createFlow($content_hub_filter->label(), $content_hub_filter->id() . '_migrated', TRUE, [
        'config' => [
          'cms_content_sync.pool.' . $pool_id,
        ],
      ], $configurations, $force_update),
      'flow_configuration' => $configurations,
      'type' => 'pull',
    ];
  }

}
