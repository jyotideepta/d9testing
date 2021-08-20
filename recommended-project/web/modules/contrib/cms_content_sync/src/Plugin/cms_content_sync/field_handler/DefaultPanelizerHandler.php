<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\field_handler;

use Drupal\cms_content_sync\Plugin\FieldHandlerBase;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\ctools\Plugin\BlockPluginCollection;

/**
 * Providing a handler for the panelizer module.
 *
 * @FieldHandler(
 *   id = "cms_content_sync_panelizer_field_handler",
 *   label = @Translation("Panelizer"),
 *   weight = 90
 * )
 */
class DefaultPanelizerHandler extends FieldHandlerBase
{
    public const SUPPORTED_PROVIDERS = [
        'block_content',
        'ctools_block',
        'views',
        'system',
        'core',
        'language',
        'social_media',
    ];

    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle, $field_name, FieldDefinitionInterface $field)
    {
        $allowed = [
            'panelizer',
        ];

        return false !== in_array($field->getType(), $allowed);
    }

    /**
     * {@inheritdoc}
     */
    public function getHandlerSettings($current_values, $type = 'both')
    {
        $options = [];

        if ('pull' !== $type) {
            $options = [
                'export_referenced_custom_blocks' => [
                    '#type' => 'checkbox',
                    '#title' => 'Push referenced custom blocks',
                    '#default_value' => $this->shouldPushReferencedBlocks(),
                ],
            ];
        }

        return $options;
    }

    /**
     * {@inheritdoc}
     */
    public function pull(PullIntent $intent)
    {
        $action = $intent->getAction();
        $entity = $intent->getEntity();

        // Deletion doesn't require any action on field basis for static data.
        if (SyncIntent::ACTION_DELETE == $action) {
            return false;
        }

        if ($intent->shouldMergeChanges()) {
            return false;
        }

        if (PullIntent::PULL_AUTOMATICALLY != $this->settings['import']) {
            return false;
        }

        $data = $intent->getProperty($this->fieldName);

        if (empty($data)) {
            $entity->set($this->fieldName, null);
        } else {
            $blockManager = \Drupal::service('plugin.manager.block');
            foreach ($data as &$item) {
                $display = &$item['panels_display'];
                if (!empty($display['blocks'])) {
                    $values = [];
                    $blockCollection = new BlockPluginCollection($blockManager, $display['blocks']);
                    foreach ($display['blocks'] as $uuid => $definition) {
                        if ('block_content' == $definition['provider']) {
                            // Use entity ID, not config ID.
                            list($type, $block_uuid) = explode(':', $definition['id']);
                            $block = \Drupal::service('entity.repository')
                                ->loadEntityByUuid($type, $block_uuid);
                            if (!$block) {
                                continue;
                            }
                        } elseif (!in_array($definition['provider'], self::SUPPORTED_PROVIDERS)) {
                            continue;
                        }

                        if (!$blockCollection->get($uuid)) {
                            $blockCollection->addInstanceId($uuid, $definition);
                        }

                        $values[$uuid] = $definition;
                    }
                    $display['blocks'] = $values;
                }
            }

            $entity->set($this->fieldName, $data);
        }

        return true;
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

        $data = $entity->get($this->fieldName)->getValue();

        foreach ($data as &$item) {
            $display = &$item['panels_display'];
            unset($display['storage_id']);

            if (!empty($display['blocks'])) {
                $blocks = [];
                foreach ($display['blocks'] as $uuid => $definition) {
                    if ('block_content' == $definition['provider']) {
                        // Use entity ID, not config ID.
                        list($type, $uuid) = explode(':', $definition['id']);
                        $block = \Drupal::service('entity.repository')
                            ->loadEntityByUuid($type, $uuid);

                        if ($this->shouldPushReferencedBlocks()) {
                            $intent->addDependency($block);
                        } else {
                            $intent->addReference($block);
                        }
                    } elseif (!in_array($definition['provider'], self::SUPPORTED_PROVIDERS)) {
                        continue;
                    }

                    $blocks[$uuid] = $definition;
                }

                $display['blocks'] = $blocks;
            }
        }

        $intent->setProperty($this->fieldName, $data);

        return true;
    }

    protected function shouldPushReferencedBlocks()
    {
        return isset($this->settings['handler_settings']['export_referenced_custom_blocks']) && 0 === $this->settings['handler_settings']['export_referenced_custom_blocks'] ? 0 : 1;
    }
}
