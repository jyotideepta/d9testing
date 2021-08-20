<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\entity_handler;

use Drupal\cms_content_sync\Plugin\EntityHandlerBase;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Entity\EntityInterface;

/**
 * Class DefaultConfigEntityHandler, providing a minimalistic implementation for
 * any config entity type.
 *
 * @EntityHandler(
 *   id = "cms_content_sync_default_config_entity_handler",
 *   label = @Translation("Default Config"),
 *   weight = 100
 * )
 */
class DefaultConfigEntityHandler extends EntityHandlerBase
{
    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle)
    {
        // Whitelist supported entity types.
        $entity_types = [
            'webform',
        ];

        return in_array($entity_type, $entity_types);
    }

    /**
     * @param \EdgeBox\SyncCore\Interfaces\Configuration\IDefineEntityType $definition
     */
    public function updateEntityTypeDefinition(&$definition)
    {
        // Add properties that are listed as "config_export" keys from the entity
        // type annotation.
        $typeMapping = [
            'uuid' => 'string',
            'boolean' => 'boolean',
            'email' => 'string',
            'integer' => 'integer',
            'float' => 'float',
            'string' => 'string',
            'text' => 'string',
            'label' => 'string',
            'uri' => 'string',
            'mapping' => 'object',
            'sequence' => 'object',
        ];

        foreach ($this->getConfigProperties() as $key => $config) {
            $type = $config['type'];
            if (empty($typeMapping[$type])) {
                continue;
            }

            $remoteType = $typeMapping[$type];
            $multiple = false;
            if ('array' === $remoteType) {
                $type = $config['sequence']['type'];
                if (empty($typeMapping[$type])) {
                    continue;
                }

                $remoteType = $typeMapping[$type];
                $multiple = true;
            }

            if ('string' === $remoteType) {
                $definition->addStringProperty($key, $config['label'], $multiple);
            } elseif ('boolean' === $remoteType) {
                $definition->addBooleanProperty($key, $config['label'], $multiple);
            } elseif ('integer' === $remoteType) {
                $definition->addIntegerProperty($key, $config['label'], $multiple);
            } elseif ('float' === $remoteType) {
                $definition->addFloatProperty($key, $config['label'], $multiple);
            } else {
                $definition->addObjectProperty($key, $config['label'], $multiple);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedPreviewOptions()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function push(PushIntent $intent, EntityInterface $entity = null)
    {
        if (!parent::push($intent, $entity)) {
            return false;
        }

        if (!$entity) {
            $entity = $intent->getEntity();
        }

        /**
         * @var \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
         */
        foreach ($this->getConfigProperties() as $property => $config) {
            $entity_property = $entity->get($property);
            if (is_array($entity_property) && !count($entity_property)) {
                $entity_property = null;
            }

            $intent->setProperty($property, $entity_property);
        }

        return true;
    }

    /**
     * Pull the remote entity.
     *
     * {@inheritdoc}
     */
    public function pull(PullIntent $intent)
    {
        $action = $intent->getAction();

        if (!parent::pull($intent)) {
            return false;
        }

        if (SyncIntent::ACTION_DELETE === $action) {
            return true;
        }

        /**
         * @var \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
         */
        $entity = $intent->getEntity();

        $forbidden_fields = $this->getForbiddenFields();

        foreach ($this->getConfigProperties() as $property => $config) {
            if (in_array($property, $forbidden_fields)) {
                continue;
            }

            $entity->set($property, $intent->getProperty($property));
        }

        $entity->save();

        return true;
    }

    /**
     * Get all config properties for this entity type.
     *
     * @return array
     */
    protected function getConfigProperties()
    {
        /**
         * @var \Drupal\Core\Config\Entity\ConfigEntityTypeInterface $entity_type
         */
        $entity_type = \Drupal::entityTypeManager()->getDefinition($this->entityTypeName);

        $properties = $entity_type->getPropertiesToExport();
        if (!$properties) {
            return [];
        }

        $config_definition = \Drupal::service('config.typed')->getDefinition($this->entityTypeName.'.'.$this->bundleName.'.*');
        if (empty($config_definition)) {
            return [];
        }

        $mapping = $config_definition['mapping'];

        $result = [];

        foreach ($properties as $property) {
            // Wrong information from webform schema definition...
            // Associative arrays are NOT sequences.
            if ('webform' === $this->entityTypeName && 'access' === $property) {
                $mapping[$property]['type'] = 'mapping';
            }

            $result[$property] = $mapping[$property];
        }

        return $result;
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
        return true;
    }
}
