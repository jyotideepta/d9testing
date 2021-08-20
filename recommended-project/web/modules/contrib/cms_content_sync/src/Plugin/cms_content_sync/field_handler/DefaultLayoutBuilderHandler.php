<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\field_handler;

use Drupal\cms_content_sync\Plugin\FieldHandlerBase;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\layout_builder\Section;

/**
 * Providing a minimalistic implementation for any field type.
 *
 * @FieldHandler(
 *   id = "cms_content_sync_default_layout_builder",
 *   label = @Translation("Default Layout Builder"),
 *   weight = 100
 * )
 */
class DefaultLayoutBuilderHandler extends FieldHandlerBase
{
    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle, $field_name, FieldDefinitionInterface $field)
    {
        return 'layout_section' == $field->getType();
    }

    /**
     * {@inheritdoc}
     */
    public function push(PushIntent $intent)
    {
        $action = $intent->getAction();
        $entity = $intent->getEntity();

        if (PushIntent::PUSH_AUTOMATICALLY != $this->settings['export']) {
            return false;
        }

        // Deletion doesn't require any action on field basis for static data.
        if (SyncIntent::ACTION_DELETE == $action) {
            return false;
        }

        $layout_builder_elements = $entity->get($this->fieldName)->getValue();

        $layout_builder_array = [];
        foreach ($layout_builder_elements as $key => $layout_builder_element) {
            /**
             * @var \Drupal\layout_builder\Section $layout_builder_element
             */
            $serialize = $layout_builder_element['section']->toArray();

            if (isset($serialize['components'])) {
                foreach ($serialize['components'] as &$component) {
                    if (isset($component['configuration']['provider'])) {
                        if ('block_content' == $component['configuration']['provider']) {
                            list($provider, $uuid) = explode(':', $component['configuration']['id']);

                            $block_storage = \Drupal::entityTypeManager()->getStorage('block_content');
                            $block = $block_storage->loadByProperties(['uuid' => $uuid]);

                            if (!empty($block)) {
                                $component['configuration']['block_reference'] = $intent->addDependency(reset($block));
                            }
                        } elseif ('layout_builder' == $component['configuration']['provider']) {
                            if (isset($component['configuration']['block_revision_id'])) {
                                $block_storage = \Drupal::entityTypeManager()->getStorage('block_content');
                                $block = $block_storage->loadByProperties(['revision_id' => $component['configuration']['block_revision_id']]);

                                unset($component['configuration']['block_revision_id']);

                                if (!empty($block)) {
                                    $component['configuration']['block_reference'] = $intent->addDependency(reset($block));
                                }
                            }
                        }
                    }
                }
            }

            $layout_builder_array[$key] = $serialize;
        }

        if (!empty($layout_builder_array)) {
            $intent->setProperty($this->fieldName, $layout_builder_array);

            return true;
        }

        return false;
    }

    public function pull(PullIntent $intent)
    {
        $layout_builder_array = $intent->getProperty($this->fieldName);
        if (!empty($layout_builder_array)) {
            $layout_builder_elements = [];
            foreach ($layout_builder_array as $key => $layout_builder_element) {
                if (isset($layout_builder_element['components'])) {
                    foreach ($layout_builder_element['components'] as $uuid => $component) {
                        if (isset($component['configuration']['provider'])) {
                            if ('layout_builder' == $component['configuration']['provider']) {
                                if (isset($component['configuration']['block_reference'])) {
                                    $block = $intent->loadEmbeddedEntity($component['configuration']['block_reference']);
                                    if ($block) {
                                        $layout_builder_element['components'][$uuid]['configuration']['block_revision_id'] = $block->get('revision_id')->value;
                                    }
                                }
                            }
                        }
                    }
                }

                $layout_builder_elements[$key]['section'] = Section::fromArray($layout_builder_element);
            }

            $intent->getEntity()->set($this->fieldName, $layout_builder_elements);
        }
    }
}
