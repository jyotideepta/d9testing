<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\field_handler;

use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Providing a minimalistic implementation for any field type.
 *
 * @FieldHandler(
 *   id = "cms_content_sync_default_bricks_handler",
 *   label = @Translation("Default Bricks"),
 *   weight = 90
 * )
 */
class DefaultBricksHandler extends DefaultEntityReferenceHandler
{
    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle, $field_name, FieldDefinitionInterface $field)
    {
        return 'bricks' == $field->getType();
    }

    protected function forcePushingReferencedEntities()
    {
        return true;
    }

    protected function allowsMerge()
    {
        return false;
    }
}
