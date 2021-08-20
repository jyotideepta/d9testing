<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\field_handler;

use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\Plugin\EntityReferenceHandlerBase;
use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use EdgeBox\SyncCore\Interfaces\Configuration\IDefineEntityType;

/**
 * Providing a minimalistic implementation for any field type.
 *
 * @FieldHandler(
 *   id = "cms_content_sync_default_entity_reference_handler",
 *   label = @Translation("Default Entity Reference"),
 *   weight = 90
 * )
 */
class DefaultEntityReferenceHandler extends EntityReferenceHandlerBase
{
    public const SUPPORTED_CONFIG_ENTITY_TYPES = [
        'block',
        'classy_paragraphs_style',
        'view',
    ];

    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle, $field_name, FieldDefinitionInterface $field)
    {
        $supported = [
            'entity_reference',
            'entity_reference_revisions',
            'bibcite_contributor',
            'viewsreference',
            'dynamic_entity_reference',
        ];

        if (!in_array($field->getType(), $supported)) {
            return false;
        }

        $types = EntityReferenceHandlerBase::getReferencedEntityTypesFromFieldDefinition($field);
        foreach ($types as $type) {
            if (in_array($type, ['user', 'brick_type', 'paragraph'])) {
                return false;
            }

            if ('menu_link' == $field_name) {
                return false;
            }

            $referenced_entity_type = \Drupal::entityTypeManager()->getStorage($type);
            if ($referenced_entity_type instanceof ConfigEntityStorage && !in_array($type, self::SUPPORTED_CONFIG_ENTITY_TYPES)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Save the push settings the user selected for paragraphs.
     *
     * @param null  $parent_entity
     * @param array $tree_position
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    public static function saveEmbeddedPushToPools(EntityInterface $entity, $parent_entity = null, $tree_position = [])
    {
        // Make sure paragraph push settings are saved as well..
        $entityTypeManager = \Drupal::entityTypeManager();
        $entityFieldManager = \Drupal::service('entity_field.manager');
        /** @var \Drupal\Core\Field\FieldDefinitionInterface[] $fields */
        $fields = $entityFieldManager->getFieldDefinitions($entity->getEntityTypeId(), $entity->bundle());
        foreach ($fields as $name => $definition) {
            if ('entity_reference_revisions' == $definition->getType()) {
                $reference_type = $definition
                    ->getFieldStorageDefinition()
                    ->getPropertyDefinition('entity')
                    ->getTargetDefinition()
                    ->getEntityTypeId();
                $storage = $entityTypeManager
                    ->getStorage($reference_type);

                $data = $entity->get($name)->getValue();
                foreach ($data as $delta => $value) {
                    if (empty($value['target_id'])) {
                        continue;
                    }

                    $target_id = $value['target_id'];
                    $reference = $storage
                        ->load($target_id);

                    if (!$reference) {
                        continue;
                    }

                    // In case the values are still present, favor those.
                    if (!empty($value['subform']['cms_content_sync_group'])) {
                        $set = $value['subform']['cms_content_sync_group'];
                        EntityStatus::accessTemporaryPushToPoolInfoForField($entity->getEntityTypeId(), $entity->uuid(), $name, $delta, $tree_position, $set['cms_content_sync_flow'], $set['cms_content_sync_pool'], !empty($set['cms_content_sync_uuid']) ? $set['cms_content_sync_uuid'] : null);
                    }

                    EntityStatus::saveSelectedPushToPoolForField($entity, $name, $delta, $reference, $tree_position);

                    self::saveEmbeddedPushToPools($reference, $entity, array_merge($tree_position, [$name, $delta, 'subform']));
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function definePropertyAtType(IDefineEntityType $type_definition)
    {
        $type_definition->addReferenceProperty($this->fieldName, $this->fieldDefinition->getLabel(), true, $this->fieldDefinition->isRequired());
    }

    /**
     * @return bool
     */
    protected function allowSubscribeFilter()
    {
        $type = $this->fieldDefinition->getSetting('target_type');

        return 'taxonomy_term' == $type;
    }

    /**
     * Get a list of array keys from $entity->field_* values that should be
     * ignored (unset before push).
     *
     * @return array
     */
    protected function getInvalidSubfields()
    {
        return ['_accessCacheability', '_attributes', '_loaded', 'top', 'target_revision_id', 'subform'];
    }
}
