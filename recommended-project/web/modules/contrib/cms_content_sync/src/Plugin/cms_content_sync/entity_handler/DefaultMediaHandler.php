<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\entity_handler;

use Drupal\cms_content_sync\Plugin\EntityHandlerBase;
use Drupal\cms_content_sync\PushIntent;
use Drupal\Core\Entity\EntityInterface;

/**
 * Class DefaultMediaHandler, providing a minimalistic implementation for the
 * media entity type.
 *
 * @EntityHandler(
 *   id = "cms_content_sync_media_entity_handler",
 *   label = @Translation("Default Media"),
 *   weight = 90
 * )
 */
class DefaultMediaHandler extends EntityHandlerBase
{
    public const USER_PROPERTY = 'uid';
    public const USER_REVISION_PROPERTY = 'revision_user';
    public const REVISION_TRANSLATION_AFFECTED_PROPERTY = 'revision_translation_affected';

    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle)
    {
        return 'media' == $entity_type;
    }

    /**
     * {@inheritdoc}
     */
    public function push(PushIntent $intent, EntityInterface $entity = null)
    {
        if (!parent::push($intent, $entity)) {
            return false;
        }

        if (!$entity) {
            $entity = $intent->getEntity();
        }

        /**
         * @var \Drupal\node\NodeInterface $entity
         */
        $this->setDateProperty($intent, 'created', intval($entity->getCreatedTime()));

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getForbiddenFields()
    {
        return array_merge(
            parent::getForbiddenFields(),
            [
                // Must be recreated automatically on remote site.
                'thumbnail',
            ]
        );
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
}
