<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\entity_handler;

use Drupal\cms_content_sync\Plugin\EntityHandlerBase;
use Drupal\Core\Entity\EntityInterface;

/**
 * Class DefaultContentEntityHandler, providing a minimalistic implementation
 * for any content entity type.
 *
 * @EntityHandler(
 *   id = "cms_content_sync_default_entity_handler",
 *   label = @Translation("Default Content"),
 *   weight = 100
 * )
 */
class DefaultContentEntityHandler extends EntityHandlerBase
{
    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle)
    {
        // Whitelist supported entity types.
        $entity_types = [
            'block_content',
            'config_pages',
            'paragraph',
            'bibcite_contributor',
            'bibcite_reference',
            'bibcite_keyword',
            'redirect',
        ];

        $moduleHandler = \Drupal::service('module_handler');
        $eck_exists = $moduleHandler->moduleExists('eck');
        if ($eck_exists) {
            $eck_entity_type = \Drupal::entityTypeManager()->getStorage('eck_entity_type')->load($entity_type);

            if (!empty($eck_entity_type)) {
                return true;
            }
        }

        return in_array($entity_type, $entity_types);
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
        // Ignore paragraphs parent_id as it is a reference id.
        return array_merge(parent::getForbiddenFields(), ['parent_id']);
    }

    public function getViewUrl(EntityInterface $entity)
    {
        if ('paragraph' === $entity->getEntityTypeId()) {
            $parent = $entity;
            do {
                $parent = $parent->getParentEntity();
            } while ($parent && 'paragraph' === $parent->getEntityTypeId());

            if (!$parent) {
                throw new \Exception("Paragraphs can't be syndicated without being embedded in Content Sync v2.");
            }

            return parent::getViewUrl($parent);
        }

        return parent::getViewUrl($entity);
    }

    /**
     * Check whether the entity type supports having a label.
     *
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     *
     * @return bool
     */
    protected function hasLabelProperty()
    {
        $moduleHandler = \Drupal::service('module_handler');
        $eck_exists = $moduleHandler->moduleExists('eck');
        if ($eck_exists) {
            $entity_type = \Drupal::entityTypeManager()->getStorage('eck_entity_type')->load($this->entityTypeName);

            if ($entity_type) {
                return $entity_type->hasTitleField();
            }
        }

        return true;
    }
}
