<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\entity_handler;

use Drupal\cms_content_sync\Plugin\cms_content_sync\field_handler\DefaultFieldCollectionHandler;
use Drupal\cms_content_sync\Plugin\EntityHandlerBase;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;

/**
 * Class DefaultFieldCollectionItemHandler.
 *
 * @EntityHandler(
 *   id = "cms_content_sync_default_field_collection_item_handler",
 *   label = @Translation("Default Field Collection Item"),
 *   weight = 90
 * )
 */
class DefaultFieldCollectionItemHandler extends EntityHandlerBase
{
    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle)
    {
        return 'field_collection_item' == $entity_type;
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedPushOptions()
    {
        return [
            PushIntent::PUSH_DISABLED,
            PushIntent::PUSH_AS_DEPENDENCY,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedPullOptions()
    {
        return [
            PullIntent::PULL_DISABLED,
            PullIntent::PULL_AS_DEPENDENCY,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedPreviewOptions()
    {
        return [
            'table' => 'Table',
            'preview_mode' => 'Preview mode',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getForbiddenFields()
    {
        $forbidden = parent::getForbiddenFields();
        $forbidden[] = 'host_type';

        return $forbidden;
    }

    /**
     * {@inheritdoc}
     */
    protected function createNew(PullIntent $intent)
    {
        $entity = parent::createNew($intent);

        $parent = DefaultFieldCollectionHandler::$currentPullIntent->getEntity();

        // Respect nested entities.
        if ($parent->isNew()) {
            $parent->save();
        }

        /**
         * @var \Drupal\field_collection\Entity\FieldCollectionItem $entity
         */
        $entity->setHostEntity($parent);

        return $entity;
    }

    /**
     * {@inheritdoc}
     */
    protected function saveEntity($entity, $intent)
    {
        // Field collections are automatically saved when their host entity is saved.
    }
}
