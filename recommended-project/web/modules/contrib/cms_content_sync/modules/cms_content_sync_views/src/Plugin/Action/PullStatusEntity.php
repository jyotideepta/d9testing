<?php

namespace Drupal\cms_content_sync_views\Plugin\Action;

use Drupal\cms_content_sync\Controller\FlowPull;
use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;

/**
 * Pull entity of status entity.
 *
 * @Action(
 *   id = "import_status_entity",
 *   label = @Translation("Force Pull"),
 *   type = "cms_content_sync_entity_status"
 * )
 */
class PullStatusEntity extends ActionBase {

  /**
   * {@inheritdoc}
   *
   * @throws \Exception
   */
  public function execute($entity = NULL) {
    /** @var \Drupal\cms_content_sync\Entity\EntityStatus $entity */
    if ($entity instanceof EntityStatus) {
      $flow = $entity->getFlow();
      try {
        FlowPull::force_pull_entity($flow->id(), $entity->get('entity_type')
          ->getValue()[0]['value'], $entity->get('entity_uuid')
          ->getValue()[0]['value']);
      }
      catch (InvalidPluginDefinitionException $e) {
        throw new \Exception($e);
      }
      catch (PluginNotFoundException $e) {
        throw new \Exception($e);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return TRUE;
  }

}
