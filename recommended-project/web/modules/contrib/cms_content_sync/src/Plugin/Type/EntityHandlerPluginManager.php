<?php

namespace Drupal\cms_content_sync\Plugin\Type;

use Drupal\cms_content_sync\Entity\Pool;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\Entity\ConfigEntityType;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Manages discovery and instantiation of entity handler plugins.
 *
 * @see \Drupal\cms_content_sync\Annotation\EntityHandler
 * @see \Drupal\cms_content_sync\Plugin\EntityHandlerBase
 * @see \Drupal\cms_content_sync\Plugin\EntityHandlerInterface
 * @see plugin_api
 */
class EntityHandlerPluginManager extends DefaultPluginManager
{
    /**
     * Constructor.
     *
     * Constructs a new
     * \Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager object.
     *
     * @param \Traversable                                  $namespaces
     *                                                                      An object that implements \Traversable which contains the root paths
     *                                                                      keyed by the corresponding namespace to look for plugin implementations
     * @param \Drupal\Core\Cache\CacheBackendInterface      $cache_backend
     *                                                                      Cache backend instance to use
     * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
     *                                                                      The module handler to invoke the alter hook with
     */
    public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler)
    {
        parent::__construct('Plugin/cms_content_sync/entity_handler', $namespaces, $module_handler, 'Drupal\cms_content_sync\Plugin\EntityHandlerInterface', 'Drupal\cms_content_sync\Annotation\EntityHandler');

        $this->setCacheBackend($cache_backend, 'cms_content_sync_entity_handler_plugins');
        $this->alterInfo('cms_content_sync_entity_handler');
    }

    /**
     * @param EntityTypeInterface|string $type
     *
     * @return bool
     */
    public static function isEntityTypeFieldable($type)
    {
        if (is_string($type)) {
            /**
             * @var \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
             */
            $entityTypeManager = \Drupal::service('entity_type.manager');
            $type = $entityTypeManager->getDefinition($type, false);
        }

        return $type ? $type->entityClassImplements('Drupal\Core\Entity\FieldableEntityInterface') : false;
    }

    /**
     * @param EntityTypeInterface|string $type
     *
     * @return bool
     */
    public static function isEntityTypeConfiguration($type)
    {
        if (is_string($type)) {
            /**
             * @var \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
             */
            $entityTypeManager = \Drupal::service('entity_type.manager');
            $type = $entityTypeManager->getDefinition($type);
        }

        return $type->entityClassImplements('Drupal\Core\Config\Entity\ConfigEntityInterface');
    }

    /**
     * @param mixed $type_key
     * @param mixed $entity_bundle_name
     */
    public static function getEntityTypeInfo($type_key, $entity_bundle_name)
    {
        static $cache = [];

        if (!empty($cache[$type_key][$entity_bundle_name])) {
            return $cache[$type_key][$entity_bundle_name];
        }

        $info = [
            'entity_type' => $type_key,
            'bundle' => $entity_bundle_name,
            'required_field_not_supported' => false,
            'optional_field_not_supported' => false,
        ];

        /**
         * @var \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
         */
        $entityTypeManager = \Drupal::service('entity_type.manager');
        $type = $entityTypeManager->getDefinition($type_key);

        /**
         * @var EntityHandlerPluginManager $entityPluginManager
         */
        $entityPluginManager = \Drupal::service('plugin.manager.cms_content_sync_entity_handler');

        $entity_handlers = $entityPluginManager->getHandlerOptions($type_key, $entity_bundle_name, true);
        if (empty($entity_handlers)) {
            $info['no_entity_type_handler'] = true;
            if ('user' == $type_key) {
                $info['security_concerns'] = true;
            } elseif ($type instanceof ConfigEntityType) {
                $info['is_config_entity'] = true;
            }
        } else {
            $info['no_entity_type_handler'] = false;

            if ('block_content' == $type_key) {
                $info['hint'] = 'except for config like block placement';
            } elseif ('paragraph' == $type_key) {
                $info['hint'] = 'Paragraphs version >= 8.x-1.3';
            } elseif ('field_collection_item' == $type_key) {
                $info['hint'] = 'Paragraphs version 8.x-1.0-alpha1';
            }

            $entity_handlers = array_keys($entity_handlers);

            $handler = $entityPluginManager->createInstance(reset($entity_handlers), [
                'entity_type_name' => $type_key,
                'bundle_name' => $entity_bundle_name,
                'settings' => [],
                'sync' => null,
            ]);

            $reserved = [];
            $pools = Pool::getAll();
            if (count($pools)) {
                $reserved = reset($pools)
                    ->getClient()
                    ->getReservedPropertyNames();
            }
            $forbidden_fields = array_merge(
                $handler->getForbiddenFields(),
                // These are standard fields defined by the Flow
                // Entity type that entities may not override (otherwise
                // these fields will collide with CMS Content Sync functionality)
                $reserved
            );

            $info['unsupported_required_fields'] = [];
            $info['unsupported_optional_fields'] = [];

            /**
             * @var FieldHandlerPluginManager $fieldPluginManager
             */
            $fieldPluginManager = \Drupal::service('plugin.manager.cms_content_sync_field_handler');

            /**
             * @var \Drupal\Core\Entity\EntityFieldManager $entityFieldManager
             */
            $entityFieldManager = \Drupal::service('entity_field.manager');

            if (!self::isEntityTypeFieldable($type)) {
                $info['fieldable'] = false;
            } else {
                $info['fieldable'] = true;

                /**
                 * @var \Drupal\Core\Field\FieldDefinitionInterface[] $fields
                 */
                $fields = $entityFieldManager->getFieldDefinitions($type_key, $entity_bundle_name);

                foreach ($fields as $key => $field) {
                    if (in_array($key, $forbidden_fields)) {
                        continue;
                    }

                    $field_handlers = $fieldPluginManager->getHandlerOptions($type_key, $entity_bundle_name, $key, $field, true);
                    if (!empty($field_handlers)) {
                        continue;
                    }

                    $name = $key.' ('.$field->getType().')';

                    if ($field->isRequired()) {
                        $info['unsupported_required_fields'][] = $name;
                    } else {
                        $info['unsupported_optional_fields'][] = $name;
                    }
                }

                $info['required_field_not_supported'] = count($info['unsupported_required_fields']) > 0;
                $info['optional_field_not_supported'] = count($info['unsupported_optional_fields']) > 0;
            }
        }

        $info['is_supported'] = !$info['no_entity_type_handler'] && !$info['required_field_not_supported'];

        return $cache[$type_key][$entity_bundle_name] = $info;
    }

    /**
     * Check whether or not the given entity type is supported.
     *
     * @param $type_key
     * @param $entity_bundle_name
     *
     * @return mixed
     */
    public static function isSupported($type_key, $entity_bundle_name)
    {
        return self::getEntityTypeInfo($type_key, $entity_bundle_name)['is_supported'];
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated in Drupal 8.2.0.
     *   Use Drupal\rest\Plugin\Type\ResourcePluginManager::createInstance()
     *   instead.
     * @see https://www.drupal.org/node/2874934
     */
    public function getInstance(array $options)
    {
        if (isset($options['id'])) {
            return $this->createInstance($options['id']);
        }

        return null;
    }

    /**
     * @param string $entity_type
     *                            The entity type of the processed entity
     * @param string $bundle
     *                            The bundle of the processed entity
     * @param bool   $labels_only
     *                            Whether to return labels instead of the whole definition
     *
     * @return array
     *               An associative array $id=>$label|$handlerDefinition to display options
     */
    public function getHandlerOptions($entity_type, $bundle, $labels_only = false)
    {
        $options = [];

        foreach ($this->getDefinitions() as $id => $definition) {
            if (!$definition['class']::supports($entity_type, $bundle)) {
                continue;
            }
            $options[$id] = $labels_only ? $definition['label']->render() : $definition;
        }

        return $options;
    }

    /**
     * Return a list of all entity types and which are supported.
     *
     * @return array
     */
    public static function getEntityTypes()
    {
        $supported_entity_types = [];

        $entity_types = \Drupal::service('entity_type.bundle.info')->getAllBundleInfo();
        ksort($entity_types);
        foreach ($entity_types as $type_key => $entity_type) {
            if ('cms_content_sync' == substr($type_key, 0, 16)) {
                continue;
            }
            ksort($entity_type);

            foreach ($entity_type as $entity_bundle_name => $entity_bundle) {
                $supported_entity_types[] = EntityHandlerPluginManager::getEntityTypeInfo($type_key, $entity_bundle_name);
            }
        }

        return $supported_entity_types;
    }

    /**
     * {@inheritdoc}
     */
    protected function findDefinitions()
    {
        $definitions = parent::findDefinitions();
        uasort($definitions, function ($a, $b) {
            return $a['weight'] <=> $b['weight'];
        });

        return $definitions;
    }
}
