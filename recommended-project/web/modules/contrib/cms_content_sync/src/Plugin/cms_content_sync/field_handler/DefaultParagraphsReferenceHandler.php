<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\field_handler;

use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Providing a minimalistic implementation for any field type.
 *
 * @FieldHandler(
 *   id = "cms_content_sync_default_paragraphs_reference_handler",
 *   label = @Translation("Default Paragraphs Reference"),
 *   weight = 90
 * )
 */
class DefaultParagraphsReferenceHandler extends MergeableEntityReferenceHandler
{
    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle, $field_name, FieldDefinitionInterface $field)
    {
        return 'entity_reference_revisions' == $field->getType();
    }
}
