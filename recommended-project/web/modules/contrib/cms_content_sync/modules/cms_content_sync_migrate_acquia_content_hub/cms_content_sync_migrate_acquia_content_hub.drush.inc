<?php

/**
 * @file
 * Contains Drush commands for Content Sync.
 */

use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\PullIntent;
use EdgeBox\SyncCore\Interfaces\IApplicationInterface;
use Drupal\cms_content_sync_migrate_acquia_content_hub\CreateStatusEntities;
use Drupal\cms_content_sync_migrate_acquia_content_hub\Form\MigratePush;
use Drupal\cms_content_sync_migrate_acquia_content_hub\Form\MigratePull;
use Drupal\cms_content_sync_migrate_acquia_content_hub\Form\MigrationBase;

/**
 * Implements hook_drush_command().
 */
function cms_content_sync_migrate_acquia_content_hub_drush_command() {
  $items['content_sync_migrate_acquia_content_hub'] = [
    'description' => dt('Migrate configuration from Acquia Content Hub to Content Sync'),
    'aliases' => ['mach'],
    'options' => [
      'type' => [
        'required' => TRUE,
        'description' => 'The type of configuration you would like to migrate.',
      ],
      'sync' => [
        'required' => FALSE,
        'description' => 'Overwrite individual options, e.g. to set automatic pull/push for a specific vocabulary.',
      ],
      'backend_url' => [
        'required' => TRUE,
        'description' => 'The sync core url.',
      ],
      'authentication_type' => [
        'required' => TRUE,
        'description' => 'Allowed values: "cookie", "basic_auth"',
      ],
      'site_id' => [
        'description' => 'The unique identifier for this page. If no site_id is set, the command will try to get the client_name configuration form the Acquia Content Hub.',
      ],
      'node_push_behavior' => [
        'required' => FALSE,
        'description' => 'Allowed values: "automatically", "manually".',
      ],
      'import_updates_behavior' => [
        'required' => FALSE,
        'description' => 'Allowed values: "force_and_forbid_editing", "allow_override", "force", "ignore".',
      ],
      'force_update' => [
        'description' => 'If this is set to true, already existent flow configurations are going to be overwritten.',
      ],
    ],
    'examples' => [
      'Create push configuration' => 'drush mach --type="push" --vocabulary="sites" --backend_url="http://test:test@drupal-content-sync:8691/rest" --authentication_type="basic_auth" --site_id="example-exporter" --node_push_behavior="automatically"',
      'Create pull configuration' => 'drush mach --type="pull" --vocabulary="sites" --backend_url="http://test:test@drupal-content-sync:8691/rest" --authentication_type="basic_auth" --site_id="example-importer" --pull_updates_behavior="force_and_forbid_editing"',
    ],
  ];

  return $items;
}

/**
 * Migrate Acquia Content Hub.
 */
function drush_cms_content_sync_migrate_acquia_content_hub_content_sync_migrate_acquia_content_hub() {
  $type = drush_get_option('type');
  $backend_url = drush_get_option('backend_url');
  $authentication_type = drush_get_option('authentication_type');
  $site_id = drush_get_option('site_id', '');
  $node_push_behavior = drush_get_option('node_push_behavior', '');
  $pull_updates_behavior = drush_get_option('pull_updates_behavior', '');
  $force_update = drush_get_option('force_update', FALSE);
  $override = drush_get_option('sync');
  if ($override) {
    $override = json_decode($override, TRUE);
  }

  // Validate type option.
  if ($type != 'push' && $type != 'pull') {
    drush_set_error('The option "type" has to be either "push" or "pull"');
    return;
  }

  // Validate authentication_type option.
  $moduleHandler = Drupal::service('module_handler');
  if ($moduleHandler->moduleExists('basic_auth')) {
    if ($authentication_type != IApplicationInterface::AUTHENTICATION_TYPE_BASIC_AUTH && $authentication_type != IApplicationInterface::AUTHENTICATION_TYPE_COOKIE) {
      drush_set_error('The option "authentication_type" has to be either "' . IApplicationInterface::AUTHENTICATION_TYPE_BASIC_AUTH . '" or "' . IApplicationInterface::AUTHENTICATION_TYPE_COOKIE . '".');
      return;
    }
  }
  else {
    drush_set_error('The option "authentication_type" has to be "' . IApplicationInterface::AUTHENTICATION_TYPE_COOKIE . '".');
    return;
  }

  // Validate site_id option.
  $acquia_client_name = Drupal::config('acquia_contenthub.admin_settings')->get('client_name');
  if ($site_id == '' && !isset($acquia_client_name) && $acquia_client_name == '') {
    drush_set_error('The option "site_id" has to be set since there is also no client_name configuration set for the Acquia Content Hub that could be taken over.');
    return;
  }

  $create_status_entities = new CreateStatusEntities();

  $operations = [];

  $pool_id = MigrationBase::DEFAULT_POOL_MACHINE_NAME;
  $pool = MigrationBase::DEFAULT_POOL;

  // Create pools.
  MigrationBase::createPools($pool, $backend_url, $authentication_type, $site_id);

  // Create export configuration.
  if ($type == 'push') {

    // Validate node_push_behavior option.
    if ($node_push_behavior == '') {
      drush_set_error('For the creation of pushing flows the node_push_behavior is required.');
      return;
    }
    if ($node_push_behavior != PushIntent::PUSH_AUTOMATICALLY && $node_push_behavior != PushIntent::PUSH_MANUALLY) {
      drush_set_error('The node pushing behavior has to be either "' . PushIntent::PUSH_AUTOMATICALLY . '" or "' . PushIntent::PUSH_MANUALLY . '"');
      return;
    }

    // Create flow.
    $flow = MigratePush::createFlow($pool_id, $node_push_behavior, $pull_updates_behavior, $force_update, $override);

    // Create status entities.
    $operations = $create_status_entities->prepare($flow['flow_id'], $flow['flow_configuration'], $pool_id, $flow['type']);

    // Done.
    drush_print('The pushing configuration has been created.');
  }

  // Create pull configuration.
  if ($type == 'pull') {

    // Validate pull_updates_behavior option.
    if ($pull_updates_behavior == '') {
      drush_set_error('For the creation of pull flows the pull_updates_behavior is required.');
      return;
    }
    if ($pull_updates_behavior != PushIntent::PUSH_AUTOMATICALLY && $node_push_behavior != PushIntent::PUSH_MANUALLY && $pull_updates_behavior != PullIntent::PULL_UPDATE_FORCE_AND_FORBID_EDITING) {
      drush_set_error('The pull_updates_behavior has to be either "' . PullIntent::PULL_UPDATE_FORCE_AND_FORBID_EDITING . '" or "' . PullIntent::PULL_UPDATE_FORCE_UNLESS_OVERRIDDEN . '" or "' . PullIntent::PULL_UPDATE_FORCE . '" or "' . PullIntent::PULL_UPDATE_IGNORE . '".');
      return;
    }

    $content_hub_filters = Drupal::entityTypeManager()
      ->getStorage('contenthub_filter')
      ->loadMultiple();

    foreach ($content_hub_filters as $content_hub_filter_id => $content_hub_filter) {
      // Create flow.
      $flow = MigratePull::createFlow($pool_id, $node_push_behavior, $pull_updates_behavior, $content_hub_filter, $force_update, $override);

      // Create status entities.
      $operations = array_merge($operations, $create_status_entities->prepare($flow['flow_id'], $flow['flow_configuration'], $pool_id, $flow['type'], $content_hub_filter->tags));
    }

    drush_print('The pull configuration has been created.');
  }

  if (!count($operations)) {
    return;
  }

  $batch = [
    'title' => t('Creating status entities'),
    'operations' => $operations,
  ];

  batch_set($batch);

  // Execute.
  drush_backend_batch_process();
}
