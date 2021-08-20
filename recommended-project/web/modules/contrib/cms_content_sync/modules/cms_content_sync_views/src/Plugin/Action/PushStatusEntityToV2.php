<?php

namespace Drupal\cms_content_sync_views\Plugin\Action;

use Drupal\cms_content_sync\Controller\Migration;
use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;

/**
 * Push entity of status entity.
 *
 * @Action(
 *   id = "push_status_entity_to_v2",
 *   label = @Translation("Test push to v2"),
 *   type = "cms_content_sync_entity_status"
 * )
 */
class PushStatusEntityToV2 extends PushStatusEntity {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    /** @var \Drupal\cms_content_sync\Entity\EntityStatus $entity */
    if (is_null($entity)) {
      return;
    }

    try {
      $source = $entity->getEntity();
      if (empty($source)) {
        \Drupal::messenger()->addMessage(t('The Entity @type @uuid doesn\'t exist locally, push skipped.', [
          '@type' => $entity->get('entity_type')->getValue()[0]['value'],
          '@uuid' => $entity->get('entity_uuid')->getValue()[0]['value'],
        ]), 'warning');
        return;
      }

      $pool = $entity->getPool();
      if(!$pool->v2Ready()) {
        \Drupal::messenger()->addMessage(t('The Pool for @type %label has not been exported to the new Sync Core, push skipped. Please go to Admin > Configuration > Web services > Content Sync to export the Pool first.', [
          '@type' => $entity->get('entity_type')->getValue()[0]['value'],
          '%label' => $source->label(),
        ]), 'warning');
        return;
      }

      $flow = $entity->getFlow();
      if (!$flow->v2Ready()) {
        \Drupal::messenger()->addMessage(t('The Flow for @type %label has not been exported to the new Sync Core, push skipped. Please go to Admin > Configuration > Web services > Content Sync to export the Flow first.', [
          '@type' => $entity->get('entity_type')->getValue()[0]['value'],
          '%label' => $source->label(),
        ]), 'warning');
        return;
      }

      Migration::useV2(TRUE);
      parent::execute($entity);
      Migration::useV2(FALSE);

      Migration::entityUsedV2($flow->id, $source->getEntityTypeId(), $source->bundle(), $source->uuid(), EntityHandlerPluginManager::isEntityTypeConfiguration($source->getEntityType()) ? $source->id() : NULL, TRUE);
    }
    finally {
      Migration::useV2(FALSE);
    }
  }
}
