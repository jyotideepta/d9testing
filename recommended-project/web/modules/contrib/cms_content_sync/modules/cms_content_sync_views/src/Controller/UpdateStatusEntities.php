<?php

namespace Drupal\cms_content_sync_views\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Update status entities.
 */
class UpdateStatusEntities extends ControllerBase {

  /**
   * Batch process callback for module installation to update status entities
   * with the required reference.
   *
   * @param $ids
   * @param $context
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public static function updateStatusEntities($ids, &$context) {
    $status_entity_storage = \Drupal::entityTypeManager()->getStorage('cms_content_sync_entity_status');
    foreach ($ids as $id) {
      $status_info_entity = $status_entity_storage->load($id);
      $referenced_entity = \Drupal::service('entity.repository')
        ->loadEntityByUuid($status_info_entity->get('entity_type')->value, $status_info_entity->get('entity_uuid')->value);
      $status_info_entity->set('entity', $referenced_entity);
      $status_info_entity->save();
    }
  }

  /**
   * Batch finished callback.
   *
   * @param $success
   * @param $results
   * @param $operations
   */
  public static function updateStatusEntitiesFinished($success, $results, $operations) {
    \Drupal::messenger()->addMessage('Status Entities have been updated.');
  }

}
