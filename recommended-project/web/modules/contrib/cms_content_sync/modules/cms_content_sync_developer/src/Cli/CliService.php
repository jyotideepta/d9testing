<?php

namespace Drupal\cms_content_sync_developer\Cli;

use Drupal\cms_content_sync\Entity\Flow;
use Drush\Exceptions\UserAbortException;

/**
 *
 */
class CliService {

  /**
   * Export the configuration to the Sync Core.
   *
   * @param $io
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function configuration_export($io) {
    $flows = Flow::getAll(FALSE);
    foreach ($flows as $flow) {

      // Get all entity type configurations.
      $entity_type_bundle_configs = $flow->getEntityTypeConfig(NULL, NULL, TRUE);

      // Update versions.
      foreach ($entity_type_bundle_configs as $config) {
        $flow->updateEntityTypeBundleVersion($config['entity_type_name'], $config['bundle_name']);
        $flow->resetVersionWarning();
      }
    }

    $io->text('Flows updated');
  }

  public static $forceEntityDeletion = FALSE;

  /**
   * Force the deletion of an entities and skip the syndication.
   *
   * @param $io
   * @param $entity_type
   * @param $options
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drush\Exceptions\UserAbortException
   */
  public function force_entity_deletion($io, $entity_type, $options) {
    self::$forceEntityDeletion = TRUE;

    $bundle = $options['bundle'];
    $entity_uuid = $options['entity_uuid'];

    if ((isset($bundle) && isset($entity_uuid)) || (!isset($bundle) && !isset($entity_uuid))) {
      $io->error('Either the bundle OR the entity_uuid option must be set.');
      return;
    }

    if (isset($entity_uuid)) {
      $entity = \Drupal::service('entity.repository')->loadEntityByUuid($entity_type, $entity_uuid);

      if (!$entity) {
        $io->error('An entity of type ' . $entity_type . ' having the uuid ' . $entity_uuid . ' does not exist.');
        return;
      }

      if (!$io->confirm(dt('Do you really want to delete the entity of type ' . $entity_type . ' having the uuid: ' . $entity_uuid . ' '))) {
        throw new UserAbortException();
      }

      $entity->delete();
      $io->success('The ' . $entity_type . ' having the uuid ' . $entity_uuid . ' has been deleted.');
      return;
    }

    if (isset($bundle)) {
      if (!$io->confirm(dt('Do you really want to delete all entities of the type: ' . $entity_type . ' having the bundle: ' . $bundle . ' ?'))) {
        throw new UserAbortException();
      }

      $bundle_key = \Drupal::entityTypeManager()
        ->getStorage($entity_type)
        ->getEntityType()->getKey('bundle');

      if ($entity_type == 'menu_link_content') {
        $bundle_key = 'menu_name';
      }

      $entities = \Drupal::entityTypeManager()
        ->getStorage($entity_type)
        ->loadByProperties([$bundle_key => $bundle]);

      foreach ($entities as $entity) {
        $entity->delete();
      }

      $io->success('All entities of type: ' . $entity_type . ' and bundle: ' . $bundle . ' have been deleted.');
      return;
    }
  }

}
