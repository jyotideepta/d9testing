<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\field_handler;

use Drupal\cms_content_sync\Plugin\EntityReferenceHandlerBase;
use Drupal\cms_content_sync\PushIntent;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Implements webform references.
 *
 * @FieldHandler(
 *   id = "cms_content_sync_default_webform_handler",
 *   label = @Translation("Default Webform"),
 *   weight = 90
 * )
 */
class DefaultWebformHandler extends EntityReferenceHandlerBase
{
    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle, $field_name, FieldDefinitionInterface $field)
    {
        if (!in_array($field->getType(), ['webform'])) {
            return false;
        }

        return true;
    }

    /**
     * Don't expose option, but force push.
     *
     * @return bool
     */
    protected function forcePushingReferencedEntities()
    {
        return false;
    }

    /**
     * @return bool
     */
    protected function allowPushingReferencedEntities()
    {
        return true;
    }

    /**
     * Don't expose option, but force push.
     *
     * @return bool
     */
    protected function forceEmbeddingReferencedEntities()
    {
        return false;
    }

    /**
     * @return string
     */
    protected function getReferencedEntityTypes()
    {
        return ['webform'];
    }

    /**
     * Get the values to be set to the $entity->field_*.
     *
     * @param $reference
     * @param \Drupal\cms_content_sync\PullIntent $intent
     *
     * @return array
     */
    protected function getFieldValuesForReference($reference, $intent)
    {
        return [
            'target_id' => $reference->id(),
        ];
    }

    /**
     * @param $value
     *
     * @throws \Drupal\Core\Entity\EntityStorageException
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * @return array|object
     */
    protected function serializeReference(PushIntent $intent, EntityInterface $reference, $value)
    {
        if ($this->shouldEmbedReferencedEntities()) {
            return $intent->embed($reference, $value);
        }
        if ($this->shouldPushReferencedEntities()) {
            return $intent->addDependency($reference, $value);
        }

        return $intent->addReference(
            $reference,
            $value
        );
    }
}
