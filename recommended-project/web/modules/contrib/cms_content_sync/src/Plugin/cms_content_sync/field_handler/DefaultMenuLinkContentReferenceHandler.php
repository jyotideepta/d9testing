<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\field_handler;

use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Reference menu references and make sure they're published as the content
 * comes available.
 *
 * @FieldHandler(
 *   id = "cms_content_sync_default_menu_link_content_reference_handler",
 *   label = @Translation("Default Menu Link Content Reference"),
 *   weight = 80
 * )
 */
class DefaultMenuLinkContentReferenceHandler extends DefaultEntityReferenceHandler
{
    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle, $field_name, FieldDefinitionInterface $field)
    {
        return 'menu_link_content' == $entity_type && 'parent' == $field_name;
    }

    /**
     * {@inheritdoc}
     */
    protected function forcePushingReferencedEntities()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function loadReferencedEntityFromFieldValue($value)
    {
        if (empty($value) || empty($value['value'])) {
            return null;
        }

        list($entity_type, $uuid) = explode(':', $value['value']);
        if ('menu_link_content' != $entity_type || empty($uuid)) {
            return null;
        }

        return \Drupal::service('entity.repository')->loadEntityByUuid(
            'menu_link_content',
            $uuid
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function getFieldValuesForReference($reference, $intent)
    {
        return 'menu_link_content:'.$reference->uuid();
    }

    /**
     * {@inheritdoc}
     */
    protected function getReferencedEntityTypes()
    {
        return ['menu_link_content'];
    }
}
