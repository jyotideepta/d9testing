<?php

namespace Drupal\cms_content_sync;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Defines the entity status schema handler.
 */
class EntityStatusStorageSchema extends SqlContentEntityStorageSchema
{
    /**
     * {@inheritdoc}
     */
    protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = false)
    {
        $schema = parent::getEntitySchema($entity_type, $reset);
        $schema['cms_content_sync_entity_status']['indexes'] += [
            'cms_content_sync_entity_status__type_uuid' => [
                'entity_type',
                'entity_uuid',
            ],
        ];

        return $schema;
    }
}
