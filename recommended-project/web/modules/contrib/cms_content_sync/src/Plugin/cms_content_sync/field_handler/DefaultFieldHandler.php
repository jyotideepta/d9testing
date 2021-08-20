<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\field_handler;

use Drupal\cms_content_sync\Plugin\FieldHandlerBase;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Providing a minimalistic implementation for any field type.
 *
 * @FieldHandler(
 *   id = "cms_content_sync_default_field_handler",
 *   label = @Translation("Default"),
 *   weight = 100
 * )
 */
class DefaultFieldHandler extends FieldHandlerBase
{
    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle, $field_name, FieldDefinitionInterface $field)
    {
        $core_field_types = [
            'boolean',
            'changed',
            'comment',
            'created',
            'daterange',
            'datetime',
            'decimal',
            'email',
            'float',
            'iframe',
            'integer',
            'language',
            'list_float',
            'list_integer',
            'list_string',
            'map',
            'range_decimal',
            'range_float',
            'range_integer',
            'string',
            'string_long',
            'telephone',
            'text',
            'text_long',
            'text_with_summary',
            'timestamp',
            'uri',
            'uuid',
        ];
        $contrib_field_types = [
            'add_to_calendar_field',
            'address',
            'address_country',
            'address_zone',
            'block_field',
            'color_field_type',
            'easychart',
            'key_value',
            'key_value_long',
            'metatag',
            'social_media',
            'soundcloud',
            'tablefield',
            'video_embed_field',
            'viewfield',
            'yearonly',
            'yoast_seo',
        ];
        $allowed = array_merge($core_field_types, $contrib_field_types);

        return false !== in_array($field->getType(), $allowed)
      && ('menu_link_content' != $entity_type || 'parent' != $field_name);
    }
}
