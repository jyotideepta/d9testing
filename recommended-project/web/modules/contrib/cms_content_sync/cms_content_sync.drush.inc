<?php

/**
 * @file
 * Contains Drush commands for Content Sync.
 */

use Drupal\cms_content_sync\Cli\Drush8Io;

/**
 * Implements hook_drush_command().
 */
function cms_content_sync_drush_command() {
  $items['content-sync-configuration-export'] = [
    'description' => dt('Export configuration to the Sync Core.'),
    'aliases' => ['cse', 'csce'],
    'options' => [
      'force' => 'Whether to ignore that another site is already using the same site ID. Useful if you change the URL of a site.',
    ],
  ];

  $items['content-sync-sync-core-login'] = [
    'description' => dt('Trigger a login for this specific site at the sync core.'),
    'aliases' => ['csscl'],
  ];

  $items['content-sync-pull'] = [
    'description' => dt('   * Kindly ask the Sync Core to pull all entities for a specific flow, or to force pull one specific entity.'),
    'aliases' => ['cs-pull'],
    'required-arguments' => TRUE,
    'arguments' => [
      'flow_id' => 'The flow the entities should be pulled from.',
    ],
    'options' => [
      'force' => 'Also update entities which have already been pulled.',
      'entity_type' => 'The type of the entity that should be pulled, e.g. "node".',
      'entity_uuid' => 'The uuid of the entity that should be pulled.',
    ],
    'examples' => [
      'drush cs-pull example_flow' => 'Pull all entities from the "example_flow".',
      'drush cs-pull example_flow --force' => 'Pull all entities from the "example_flow" and force entities which already have been pulled to be updated as well.',
      'drush cs-pull example_flow --entity_type="node" --entity_uuid="3a150294-90eb-48c2-911d-672043a45683"' => 'Force pull the node having the uuid 3a150294-90eb-48c2-911d-672043a45683 from the example flow.',
    ],
  ];

  $items['content-sync-push'] = [
    'description' => dt('Push entities to the Sync Core.'),
    'aliases' => ['cs-push', 'content-sync-push-entities'],
    'required-arguments' => TRUE,
    'arguments' => [
      'flow_id' => 'The id of the flow the entities should be pushed for.',
    ],
    'options' => [
      'push_mode' => 'Allowed values: automatic_manual or automatic_manual_force',
    ],
    'examples' => [
      'drush cs-push example_flow' => 'Push all entities from the "example_flow" which have export configured as "automatically".',
      'drush cs-push example_flow --push_mode="automatic_manual"' => 'Push all entities from the "example_flow" which have push configured as "automatically" or "manually". Only pushes manually pushed entities which have not been pushed before.',
      'drush cs-push example_flow --push_mode="automatic_manual_force"' => 'Push all entities from the "example_flow" which have push configured as "automatically" or "manually". Also pushes entities which have not been pushed before by the manual push.',
    ],
  ];

  // @deprecated Function is deprecated and is going to be removed in 2.0, use pull() instead.
  $items['content-sync-pull-entities'] = [
    'description' => dt('Pull entities from Sync Core.'),
    'aliases' => ['cspe'],
    'required-arguments' => TRUE,
    'arguments' => [
      'flow_id' => 'The flow the entities should be pulled from.',
    ],
    'options' => [
      'force' => 'Also update entities which have already been pulled.',
    ],
    'examples' => [
      'drush cspe example_flow' => 'Pull all entities from the "example_flow".',
      'drush cspe example_flow --force' => 'Pull all entities from the "example_flow" and force entities which already have been pulled to be updated as well.',
    ],
  ];

  // @deprecated Function is deprecated and is going to be removed in 2.0, use pull() instead.
  $items['content-sync-force-pull-entity'] = [
    'description' => dt('Kindly ask the Sync Core to force pull one specific entity.'),
    'required-arguments' => TRUE,
    'arguments' => [
      'flow_id' => 'The flow the entity should be pulled from.',
      'entity_type' => 'The type of the entity that should be pulled.',
      'entity_uuid' => 'The uuid of the entity that should be pulled.',
    ],
    'examples' => [
      'drush content-sync-force-pull-entity example_flow node 3a150294-90eb-48c2-911d-672043a45683' => 'Force pull the node having the uuid 3a150294-90eb-48c2-911d-672043a45683 which has been imported from the example flow.',
    ],
  ];

  $items['content-sync-reset-status-entities'] = [
    'description' => dt('Reset the status entities for a specific or all pool/s.'),
    'aliases' => ['csrse'],
    'options' => [
      'pool_id' => 'The machine name of the pool the status entities should be reset for.',
    ],
    'examples' => [
      'drush csrse' => 'Reset all status entities for all pools',
      'drush csrse --pool_id="example_pool"' => 'Reset all status entities for the "example_pool".',
    ],
  ];

  $items['content-sync-check-entity-flags'] = [
    'description' => dt('Check the flags for an entity.'),
    'aliases' => ['cscef'],
    'required-arguments' => TRUE,
    'arguments' => [
      'entity_uuid' => 'The related entities uuid.',
    ],
    'options' => [
      'flag' => 'The flag to check for, allowed values are: FLAG_IS_SOURCE_ENTITY, FLAG_PUSH_ENABLED, FLAG_DEPENDENCY_PUSH_ENABLED, FLAG_EDIT_OVERRIDE, FLAG_USER_ENABLED_PUSH, FLAG_DELETED',
    ],
    'examples' => [
      'drush cscef 16cc0d54-d93d-45b8-adf2-071de9d2d32b' => 'Get all flags for the entity having the uuid = "16cc0d54-d93d-45b8-adf2-071de9d2d32b".',
      'drush cscef 16cc0d54-d93d-45b8-adf2-071de9d2d32b --flag="FLAG_EDIT_OVERRIDE"' => 'Check if the entity having the uuid = "16cc0d54-d93d-45b8-adf2-071de9d2d32b" is overridden locally.',
    ],
  ];

  return $items;
}

/**
 * Export configuration to the Content Sync backend.
 */
function drush_cms_content_sync_content_sync_configuration_export() {
  $options['force'] = drush_get_option('force', FALSE);
  Drupal::service('cms_content_sync.cli')->configuration_export(new Drush8Io(), $options);
}

/**
 * Kindly ask the Sync Core to login again.
 */
function drush_cms_content_sync_content_sync_sync_core_login() {
  Drupal::service('cms_content_sync.cli')->sync_core_login(new Drush8Io());
}

/**
 * Kindly ask the Sync Core to pull all entities for a specific flow, or to
 * force pull one specific entity.
 *
 * @param $flow_id
 */
function drush_cms_content_sync_content_sync_pull($flow_id) {
  $options['force'] = drush_get_option('force', FALSE);
  $options['entity_type'] = drush_get_option('entity_type', NULL);
  $options['entity_uuid'] = drush_get_option('entity_uuid', NULL);
  Drupal::service('cms_content_sync.cli')->pull(new Drush8Io(), $flow_id, $options);
}

/**
 * Push entities to the Sync Core.
 *
 * @param string $flow_id
 */
function drush_cms_content_sync_content_sync_push($flow_id) {
  $options['push_mode'] = drush_get_option('push_mode');
  Drupal::service('cms_content_sync.cli')->push(new Drush8Io(), $flow_id, $options);
}

/**
 * Pull entities from the Sync Core.
 *
 * @param string $flow_id
 *
 * @deprecated Function is deprecated and is going to be removed in 2.0, use pull() instead.
 */
function drush_cms_content_sync_content_sync_pull_entities($flow_id) {
  $options['force'] = drush_get_option('force', FALSE);
  Drupal::service('cms_content_sync.cli')->pull_entities(new Drush8Io(), $flow_id, $options);
}

/**
 * Kindly ask the Sync Core to force pull a specific entity.
 *
 * @param string $flow_id
 * @param string $entity_type
 * @param string $entity_uuid
 *
 * @deprecated Function is deprecated and is going to be removed in 2.0, use pull() instead.
 */
function drush_cms_content_sync_content_sync_force_pull_entity($flow_id, $entity_type, $entity_uuid) {
  Drupal::service('cms_content_sync.cli')->force_pull_entity(new Drush8Io(), $flow_id, $entity_type, $entity_uuid);
}

/**
 * Reset the status entities for a specific or all pool/s.
 */
function drush_cms_content_sync_content_sync_reset_status_entities() {
  $options['pool_id'] = drush_get_option('pool_id');
  Drupal::service('cms_content_sync.cli')->reset_status_entities(new Drush8Io(), $options);
}

/**
 * Check the flags for an entity.
 *
 * @param $entity_uuid
 */
function drush_cms_content_sync_content_sync_check_entity_flags($entity_uuid) {
  $options['flag'] = drush_get_option('flag');
  Drupal::service('cms_content_sync.cli')->check_entity_flags(new Drush8Io(), $entity_uuid, $options);
}
