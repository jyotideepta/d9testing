<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\field_handler;

use Drupal\cms_content_sync\PullIntent;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Providing a minimalistic implementation for any field type.
 *
 * @FieldHandler(
 *   id = "cms_content_sync_default_field_collection_handler",
 *   label = @Translation("Default Field Collection"),
 *   weight = 90
 * )
 */
class DefaultFieldCollectionHandler extends DefaultEntityReferenceHandler
{
    /**
     * @var \Drupal\cms_content_sync\Plugin\FieldHandlerInterface
     */
    public static $currentFieldHandler;

    /**
     * @var \Drupal\cms_content_sync\PullIntent
     */
    public static $currentPullIntent;

    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle, $field_name, FieldDefinitionInterface $field)
    {
        if (!in_array($field->getType(), ['field_collection'])) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function forceEmbeddingReferencedEntities()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function getReferencedEntityTypes()
    {
        return ['field_collection_item'];
    }

    /**
     * {@inheritdoc}
     */
    protected function loadReferencedEntity(PullIntent $intent, $definition)
    {
        $previousFieldHandler = self::$currentFieldHandler;
        $previousPullIntent = self::$currentPullIntent;

        // Expose current field and intent (to reference host entity)
        // As field collections require this when being created.
        self::$currentFieldHandler = $this;
        self::$currentPullIntent = $intent;

        $entity = parent::loadReferencedEntity($intent, $definition);

        self::$currentFieldHandler = $previousFieldHandler;
        self::$currentPullIntent = $previousPullIntent;

        return $entity;
    }

    /**
     * {@inheritdoc}
     */
    protected function loadReferencedEntityFromFieldValue($value)
    {
        if (empty($value['revision_id'])) {
            return null;
        }

        return \Drupal::entityTypeManager()->getStorage('field_collection_item')->loadRevision($value['revision_id']);
    }

    protected function getInvalidSubfields()
    {
        return ['_accessCacheability', '_attributes', '_loaded', 'top', 'target_revision_id', 'subform', 'value', 'revision_id'];
    }

    /**
     * @param $reference
     * @param \Drupal\cms_content_sync\PullIntent $intent
     *
     * @return array
     */
    protected function getFieldValuesForReference($reference, $intent)
    {
        $entity = $intent->getEntity();

        $reference->host_type = $entity->getEntityTypeId();
        $reference->host_id = $entity->id();
        $reference->host_entity = $entity;
        $reference->field_name = $this->fieldName;

        $reference->save(true);

        return [
            'value' => $reference->id(),
            'revision_id' => $reference->getRevisionId(),
        ];
    }
}
