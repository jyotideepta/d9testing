<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\field_handler;

use Drupal\cms_content_sync\Plugin\FieldHandlerBase;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Url;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use EdgeBox\SyncCore\V1\Entity\Entity;

/**
 * Providing a minimalistic implementation for any field type.
 *
 * @FieldHandler(
 *   id = "cms_content_sync_default_link_handler",
 *   label = @Translation("Default Link"),
 *   weight = 90
 * )
 */
class DefaultLinkHandler extends FieldHandlerBase
{
    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle, $field_name, FieldDefinitionInterface $field)
    {
        $allowed = ['link'];

        return false !== in_array($field->getType(), $allowed);
    }

    /**
     * {@inheritdoc}
     */
    public function getHandlerSettings($current_values, $type = 'both')
    {
        $options = [];

        if ('pull' !== $type) {
            $options['export_as_absolute_url'] = [
                '#type' => 'checkbox',
                '#title' => 'Push as absolute URL',
                '#default_value' => isset($current_values['export_as_absolute_url']) ? $current_values['export_as_absolute_url'] : false,
            ];
        }

        return array_merge(parent::getHandlerSettings($current_values, $type), $options);
    }

    /**
     * {@inheritdoc}
     */
    public function pull(PullIntent $intent)
    {
        $action = $intent->getAction();
        /**
         * @var \Drupal\Core\Entity\FieldableEntityInterface $entity
         */
        $entity = $intent->getEntity();

        // Deletion doesn't require any action on field basis for static data.
        if (SyncIntent::ACTION_DELETE == $action) {
            return false;
        }

        if ($intent->shouldMergeChanges()) {
            return false;
        }

        $data = $intent->getProperty($this->fieldName);

        if (empty($data)) {
            $entity->set($this->fieldName, null);
        } else {
            $result = [];

            foreach ($data as &$link_element) {
                if (empty($link_element['uri'])) {
                    try {
                        $reference = $intent->loadEmbeddedEntity($link_element);
                    } catch (\Exception $e) {
                        $reference = null;
                    }
                    if ($reference) {
                        $result[] = [
                            'uri' => 'entity:'.$reference->getEntityTypeId().'/'.$reference->id(),
                            'title' => $link_element['title'],
                            'options' => $link_element['options'],
                        ];
                    } elseif (!empty($reference[Entity::ENTITY_TYPE_KEY]) && !empty($link_element[Entity::BUNDLE_KEY])) {
                        if ($reference) {
                            $result[] = [
                                'uri' => 'entity:'.$reference->getEntityTypeId().'/'.$reference->id(),
                                'title' => $link_element['title'],
                                'options' => $link_element['options'],
                            ];
                        }
                        // Menu items are created before the node as they are embedded
                        // entities. For the link to work however the node must already
                        // exist which won't work. So instead we're creating a temporary
                        // uri that uses the entity UUID instead of it's ID. Once the node
                        // is pulled it will look for this link and replace it with the
                        // now available entity reference by ID.
                        elseif ($entity instanceof MenuLinkContent && 'link' == $this->fieldName) {
                            $result[] = [
                                'uri' => 'internal:/'.$link_element[Entity::ENTITY_TYPE_KEY].'/'.$link_element[Entity::UUID_KEY],
                                'title' => $link_element['title'],
                                'options' => $link_element['options'],
                            ];
                        }
                    }
                } else {
                    $result[] = [
                        'uri' => $link_element['uri'],
                        'title' => $link_element['title'],
                        'options' => $link_element['options'],
                    ];
                }
            }

            $entity->set($this->fieldName, $result);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function push(PushIntent $intent)
    {
        $action = $intent->getAction();
        /**
         * @var \Drupal\Core\Entity\FieldableEntityInterface $entity
         */
        $entity = $intent->getEntity();

        // Deletion doesn't require any action on field basis for static data.
        if (SyncIntent::ACTION_DELETE == $action) {
            return false;
        }

        $data = $entity->get($this->fieldName)->getValue();

        $absolute = !empty($this->settings['handler_settings']['export_as_absolute_url']);

        $result = [];

        foreach ($data as $key => $value) {
            $uri = &$data[$key]['uri'];
            // Find the linked entity and replace it's id with the UUID
            // References have following pattern: entity:entity_type/entity_id.
            preg_match('/^entity:(.*)\/(\d*)$/', $uri, $found);
            if (empty($found) || $absolute) {
                if ($absolute) {
                    $uri = Url::fromUri($uri, ['absolute' => true])->toString();
                }
                $result[] = [
                    'uri' => $uri,
                    'title' => isset($value['title']) ? $value['title'] : null,
                    'options' => $value['options'],
                ];
            } else {
                $link_entity_type = $found[1];
                $link_entity_id = $found[2];
                $entity_manager = \Drupal::entityTypeManager();
                $link_entity = $entity_manager->getStorage($link_entity_type)
                    ->load($link_entity_id);

                if (empty($link_entity)) {
                    continue;
                }

                if (!$this->flow->supportsEntity($link_entity)) {
                    continue;
                }

                $result[] = $intent->addReference(
                    $link_entity,
                    [
                        'title' => $value['title'],
                        'options' => $value['options'],
                    ]
                );
            }
        }

        $intent->setProperty($this->fieldName, $result);

        return true;
    }
}
