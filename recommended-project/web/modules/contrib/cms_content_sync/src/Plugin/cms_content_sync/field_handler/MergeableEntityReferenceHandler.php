<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\field_handler;

use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\PullIntent;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Providing a minimalistic implementation for any field type.
 *
 * @FieldHandler(
 *   id = "cms_content_sync_mergeable_entity_reference_handler",
 *   label = @Translation("Default Entity Reference"),
 *   weight = 90
 * )
 */
class MergeableEntityReferenceHandler extends DefaultEntityReferenceHandler
{
    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle, $field_name, FieldDefinitionInterface $field)
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getHandlerSettings($current_values, $type = 'both')
    {
        $options = [];

        if ($this->fieldDefinition->getFieldStorageDefinition()->isMultiple() && 'push' !== $type) {
            $options['merge_local_changes'] = [
                '#type' => 'checkbox',
                '#title' => 'Merge local changes',
                '#default_value' => isset($current_values['merge_local_changes']) ? $current_values['merge_local_changes'] : false,
            ];
        }

        return array_merge(parent::getHandlerSettings($current_values, $type), $options);
    }

    protected function setValues(PullIntent $intent)
    {
        /**
         * @var \Drupal\Core\Entity\FieldableEntityInterface $entity
         */
        $entity = $intent->getEntity();

        $entityTypeManager = \Drupal::entityTypeManager();

        // Since this is only used for paragraphs, there is only one entity type
        // existent.
        $reference_type = $this->getReferencedEntityTypes()[0];
        $storage = $entityTypeManager
            ->getStorage($reference_type);

        $data = $intent->getProperty($this->fieldName);

        $merge = !empty($this->settings['handler_settings']['merge_local_changes']);

        if (!$merge && $intent->shouldMergeChanges()) {
            return false;
        }

        if (empty($data) && !$merge) {
            $entity->set($this->fieldName, null);

            return true;
        }
        if (!$merge) {
            return parent::setValues($intent);
        }

        if (null === $data) {
            $data = [];
        }

        $reference_ids = [];
        $ids = [];
        foreach ($data as $value) {
            $reference = $intent->loadEmbeddedEntity($value);
            if ($reference) {
                $reference_data = [
                    'target_id' => $reference->id(),
                ];
                $ids[] = $reference->id();

                if ($reference instanceof RevisionableInterface) {
                    $reference_data['target_revision_id'] = $reference->getRevisionId();
                }

                $reference_ids[] = $reference_data;
            }
        }
        $overwrite_ids = $reference_ids;

        if ($merge && $intent->shouldMergeChanges()) {
            $last_overwrite_values = $intent->getStatusData(['field', $this->fieldName, 'last_overwrite_values']);
            $last_pull_order = $intent->getStatusData(['field', $this->fieldName, 'last_imported_values']);
            $previous = $entity->get($this->fieldName)->getValue();
            $previous_ids = [];
            $previous_id_to_definition = [];
            foreach ($previous as $value) {
                if (empty($value['target_id'])) {
                    continue;
                }
                $previous_id_to_definition[$value['target_id']] = $value;
                $previous_ids[] = $value['target_id'];
            }

            // Check if there actually are any local overrides => otherwise just
            // overwrite local references with new references and new order.
            if (!is_null($last_pull_order)) {
                $merged = [];
                $merged_ids = [];

                // First add all existing entities to the new value (merged items)
                foreach ($previous_ids as $target_id) {
                    $reference = $storage
                        ->load($target_id);
                    if (!$reference) {
                        continue;
                    }

                    // Removed from remote => remove locally.
                    if (!in_array($target_id, $ids)) {
                        $info = EntityStatus::getInfoForEntity($reference->getEntityTypeId(), $reference->uuid(), $intent->getFlow(), $intent->getPool());
                        // But only if it was actually pulled.
                        if ($info && !$info->isSourceEntity()) {
                            continue;
                        }
                    }

                    $merged[] = $previous_id_to_definition[$target_id];
                    $merged_ids[] = $target_id;
                }

                // Next add all newly added items where they fit best.
                if (count($reference_ids)) {
                    for ($i = 0; $i < count($reference_ids); ++$i) {
                        $def = $reference_ids[$i];
                        $id = $def['target_id'];
                        // Already present? Ignore.
                        if (in_array($id, $merged_ids)) {
                            continue;
                        }

                        // Deleted locally? Ignore.
                        if (in_array($def, $last_overwrite_values)) {
                            continue;
                        }

                        // Get the index of the item before this one, so we can add ours
                        // after it. If this doesn't work, it will be the first item
                        // in the new item set.
                        $n = $i - 1;
                        $index = false;
                        while (false === $index && $n >= 0) {
                            $index = array_search($reference_ids[$n]['target_id'], $merged_ids);
                            --$n;
                        }

                        // First and unfound come first.
                        if (0 === $i || false === $index) {
                            array_unshift($merged, $def);
                            array_unshift($merged_ids, $id);

                            continue;
                        }
                        // Everything else comes behind the last item that exists.
                        array_splice($merged, $index + 1, 0, [$def]);
                        array_splice($merged_ids, $index + 1, 0, $id);
                    }
                }

                $reference_ids = $merged;
                $ids = $merged_ids;
            }
        }

        if ('paragraph' == $this->fieldDefinition->getSetting('target_type')) {
            foreach ($reference_ids as $def) {
                $paragraph = Paragraph::load($def['target_id']);
                if (!$paragraph->getParentEntity()) {
                    /**
                     * @var \Drupal\Core\Entity\ContentEntityInterface $entity
                     */
                    $paragraph->setParentEntity($entity, $this->fieldName);
                }
            }
        }

        if (!$merge || !$intent->shouldMergeChanges() || $overwrite_ids !== $last_overwrite_values) {
            $entity->set($this->fieldName, count($reference_ids) ? $reference_ids : null);
            $intent->setStatusData([
                'field',
                $this->fieldName,
                'last_imported_values',
            ], $ids);
            $intent->setStatusData([
                'field',
                $this->fieldName,
                'last_overwrite_values',
            ], $overwrite_ids);
        }

        return true;
    }
}
