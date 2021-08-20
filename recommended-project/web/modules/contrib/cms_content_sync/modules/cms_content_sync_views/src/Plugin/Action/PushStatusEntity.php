<?php

namespace Drupal\cms_content_sync_views\Plugin\Action;

use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;

/**
 * Push entity of status entity.
 *
 * @Action(
 *   id = "export_status_entity",
 *   label = @Translation("Force Push"),
 *   type = "cms_content_sync_entity_status"
 * )
 */
class PushStatusEntity extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    /** @var \Drupal\cms_content_sync\Entity\EntityStatus $entity */
    if (!is_null($entity)) {
      $source = $entity->getEntity();
      if (empty($source)) {
        \Drupal::messenger()->addMessage(t('The Entity @type @uuid doesn\'t exist locally, push skipped.', [
          '@type' => $entity->get('entity_type')->getValue()[0]['value'],
          '@uuid' => $entity->get('entity_uuid')->getValue()[0]['value'],
        ]), 'warning');
        return;
      }

      $flow = $entity->getFlow();
      if (empty($flow)) {
        \Drupal::messenger()->addMessage(t('The Flow for @type %label doesn\'t exist anymore, push skipped.', [
          '@type' => $entity->get('entity_type')->getValue()[0]['value'],
          '%label' => $source->label(),
        ]), 'warning');
        return;
      }

      $pool = $entity->getPool();
      if (empty($pool)) {
        \Drupal::messenger()->addMessage(t('The Pool for @type %label doesn\'t exist anymore, push skipped.', [
          '@type' => $entity->get('entity_type')->getValue()[0]['value'],
          '%label' => $source->label(),
        ]), 'warning');
        return;
      }

      if (!$flow->canPushEntity($source, PushIntent::PUSH_ANY, SyncIntent::ACTION_CREATE, $pool)) {
        \Drupal::messenger()->addMessage(t('The Flow @flow for @type %label doesn\'t allow pushing to the pool @pool.', [
          '@flow' => $flow->id,
          '@type' => $entity->get('entity_type')->getValue()[0]['value'],
          '%label' => $source->label(),
          '@pool' => $pool->id,
        ]), 'warning');
        return;
      }

      if ($entity->wasPushedEmbedded()) {
        $parent = $entity->getParentEntity();
        $raw = $entity->getData(EntityStatus::DATA_PARENT_ENTITY);
        \Drupal::messenger()->addMessage(t("The @type %label was pushed embedded into another entity. Please push the parent entity @parent_type %parent_label (@parent_uuid) instead.", [
          '@type' => $entity->get('entity_type')->getValue()[0]['value'],
          '%label' => $source->label(),
          '@parent_type' => $raw['type'],
          '@parent_uuid' => $raw['uuid'],
          '%parent_label' => $parent ? $parent->label() : '',
        ]), 'warning');
        return;
      }

      PushIntent::pushEntityFromUi($source, PushIntent::PUSH_FORCED, SyncIntent::ACTION_CREATE, $flow, $pool);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return TRUE;
  }

}
