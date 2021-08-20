<?php

namespace Drupal\cms_content_sync_draggableviews\EventSubscriber;

use Drupal\cms_content_sync\Event\AfterEntityPull;
use Drupal\cms_content_sync\Event\BeforeEntityPush;
use Drupal\cms_content_sync\Event\BeforeEntityTypeExport;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\field_collection\Entity\FieldCollectionItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriptions for events dispatched by Content Sync.
 */
class DraggableViewsSyncExtend implements EventSubscriberInterface {

  const PROPERTY_NAME = 'draggableviews';

  /**
   * Returns an array of event names this subscriber wants to listen to.
   *
   * @return array
   *   The event names to listen to
   */
  public static function getSubscribedEvents() {
    $events[BeforeEntityPush::EVENT_NAME][] = ['extendPush'];
    $events[AfterEntityPull::EVENT_NAME][] = ['extendPull'];
    $events[BeforeEntityTypeExport::EVENT_NAME][] = ['extendEntityType'];
    return $events;
  }

  /**
   * Whether or not this entity type supports being displayed in a draggable view.
   *
   * @param string $entity_type_name
   *
   * @return bool Whether or not the simple sitemap module supports configuring
   *   sitemap settings for the given entity type + bundle.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function supportsEntityType($entity_type_name) {
    /**
     * @var \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
     */
    $entity_type_manager = \Drupal::service('entity_type.manager');
    $entity_type = $entity_type_manager->getDefinition($entity_type_name, FALSE);

    if (!$entity_type instanceof ContentEntityTypeInterface) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Add the field to the entity type for the draggable views weight configuration.
   *
   * @param \Drupal\cms_content_sync\Event\BeforeEntityTypeExport $event
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function extendEntityType(BeforeEntityTypeExport $event) {
    if (!$this->supportsEntityType($event->getEntityTypeName())) {
      return;
    }

    $event
      ->getDefinition()
      ->addObjectProperty(self::PROPERTY_NAME, 'Draggable views', FALSE);
  }

  /**
   * Alter the push to include the draggable views settings, if enabled for the entity
   * type.
   *
   * @param \Drupal\cms_content_sync\Event\BeforeEntityPush $event
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function extendPush(BeforeEntityPush $event) {
    $intent = $event->intent;
    $entity = $event->entity;

    if (!$this->supportsEntityType($entity->getEntityTypeId())) {
      return;
    }

    $connection = Database::getConnection();
    $query = $connection->select('draggableviews_structure', 'dvs');
    $query->condition('dvs.entity_id', $entity->id());
    $query->fields('dvs', [
      'view_name',
      'view_display',
      'args',
      'weight',
      'parent',
    ]);
    $result = $query->execute();
    $result->setFetchMode(\PDO::FETCH_ASSOC);
    $result = $result->fetchAll();

    $values = [];
    foreach ($result as $row) {
      $values[$row['view_name']][$row['view_display']] = [
        'args' => $row['args'],
        'weight' => $row['weight'],
        'parent' => 0,
      ];
    }

    if (!count($values)) {
      $intent->setProperty(self::PROPERTY_NAME, NULL);
      return;
    }

    $intent->setProperty(self::PROPERTY_NAME, $values);
  }

  /**
   * @param \Drupal\cms_content_sync\Event\BeforeEntityPull $event
   *
   * @internal param $entity
   * @internal param $intent
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function extendPull(AfterEntityPull $event) {
    $intent = $event->getIntent();
    $entity = $event->getEntity();

    if (!$this->supportsEntityType($entity->getEntityTypeId())) {
      return;
    }

    // Field Collection entities are causing an exception when used
    // with draggable views.
    if ($entity instanceof FieldCollectionItem) {
      return;
    }

    $values = $intent->getProperty(self::PROPERTY_NAME);

    if (empty($values)) {
      return;
    }

    $connection = Database::getConnection();

    foreach ($values as $view_name => $view_values) {
      foreach ($view_values as $view_display => $display_values) {
        // Remove old data.
        $connection->delete('draggableviews_structure')
          ->condition('view_name', $view_name)
          ->condition('view_display', $view_display)
          ->condition('entity_id', $entity->id())
          ->execute();

        // Add new data.
        $record = [
          'view_name' => $view_name,
          'view_display' => $view_display,
          'args' => $display_values['args'],
          'entity_id' => $entity->id(),
          'weight' => $display_values['weight'],
          'parent' => $display_values['parent'],
        ];

        $connection
          ->insert('draggableviews_structure')
          ->fields($record)
          ->execute();
      }
    }
  }

}
