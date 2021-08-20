<?php

/**
 * @file
 * Contains Drush commands for Content Sync.
 */

use Drupal\cms_content_sync\Cli\Drush8Io;

/**
 * Implements hook_drush_command().
 */
function cms_content_sync_developer_drush_command() {
  $items['content-sync-update-flows'] = [
    'description' => dt('Update all flows'),
    'aliases' => ['csuf'],
  ];

  $items['content-sync-force-entity-deletion'] = [
    'description' => dt('   * Kindly ask the Sync Core to pull all entities for a specific flow, or to force pull one specific entity.'),
    'aliases' => ['csfed'],
    'required-arguments' => TRUE,
    'arguments' => [
      'entity_type' => 'The entity type the entities should be deleted for.',
    ],
    'options' => [
      'bundle' => 'The bundle the entities should be deleted for.',
      'entity_uuid' => 'The entities uuid that should be deleted.',
    ],
    'examples' => [
      'drush csfed node --entity_uuid="06d1d5b8-5583-4929-9f7c-c85cfe59440b"' => 'Force delete the node having the uuid: "06d1d5b8-5583-4929-9f7c-c85cfe59440b".',
      'drush csfed node --bundle="basic_page"' => 'Force delete all nodes having the bundle basic_page.',
    ],
  ];

  return $items;

}

/**
 * Update flows.
 */
function drush_cms_content_sync_content_sync_update_flows() {
  Drupal::service('cms_content_sync_developer.cli')->configuration_export(new Drush8Io());
}

/**
 * Force the deletion of entities and skip the syndication.
 *
 * @param $entity_type
 */
function drush_content_sync_force_entity_deletion($entity_type) {
  $options['entity_uuid'] = drush_get_option('entity_uuid', NULL);
  $options['bundle'] = drush_get_option('bundle', NULL);
  Drupal::service('cms_content_sync_developer.cli')->force_entity_deletion(new Drush8Io(), $entity_type . $options);
}
