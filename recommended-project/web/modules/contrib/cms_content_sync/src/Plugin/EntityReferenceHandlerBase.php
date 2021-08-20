<?php

namespace Drupal\cms_content_sync\Plugin;

use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use EdgeBox\SyncCore\V1\Entity\Entity;
use function t;

/**
 * Providing a base implementation for any reference field type.
 */
abstract class EntityReferenceHandlerBase extends FieldHandlerBase
{
    /**
     * {@inheritdoc}
     */
    public function getHandlerSettings($current_values, $type = 'both')
    {
        $options = [];

        // Will be added in an upcoming release and recommended for paragraphs
        // and bricks.
        // Other entity types like media or taxonomy can use this as a performance
        // improvement as well.
        /*if(!$this->forceReferencedEntityEmbedding()) {
        $options = [
        'embed_referenced_entities' => [
        '#type' => 'checkbox',
        '#title' => 'Embed referenced entities',
        '#default_value' => $this->shouldEmbedReferencedEntities(),
        ],
        ];
        }*/

        $referenced_entity_types = $this->getReferencedEntityTypes();
        if (!$this->forcePushingReferencedEntities() && !$this->forceEmbeddingReferencedEntities() && 'pull' !== $type && !in_array('view', $referenced_entity_types) && !in_array('classy_paragraphs_style', $referenced_entity_types)) {
            $options['export_referenced_entities'] = [
                '#type' => 'checkbox',
                '#title' => 'Push referenced entities',
                '#default_value' => isset($current_values['export_referenced_entities']) ? $current_values['export_referenced_entities'] : $this->shouldPushReferencedEntities(true),
            ];
        }

        if ($this->allowSubscribeFilter() && $this->flow && 'push' !== $type) {
            $type = $this->fieldDefinition->getSetting('target_type');
            $bundles = $this->fieldDefinition->getSetting('target_bundles');
            if (!$bundles) {
                $field_settings = $this->fieldDefinition->getSettings();
                if (isset($field_settings['handler_settings']['target_bundles'])) {
                    $bundles = $field_settings['handler_settings']['target_bundles'];
                }
            }

            global $config;
            $config_key = $this->entityTypeName.'-'.$this->bundleName.'-'.$this->fieldName;
            $disabled = !empty($config['cms_content_sync.flow.'.$this->flow->id()]['sync_entities'][$config_key]['handler_settings']['subscribe_only_to']);

            $entities = [];
            $current = $disabled ? $config['cms_content_sync.flow.'.$this->flow->id()]['sync_entities'][$config_key]['handler_settings']['subscribe_only_to'] : (empty($current_values['subscribe_only_to']) ? null : $current_values['subscribe_only_to']);
            if (!empty($current)) {
                $storage = \Drupal::entityTypeManager()->getStorage($type);
                $repository = \Drupal::service('entity.repository');

                foreach ($current as $ref) {
                    $entity = null;

                    if (isset($ref['uuid'])) {
                        $entity = $repository->loadEntityByUuid($ref['type'], $ref['uuid']);
                    } elseif (isset($ref['target_id'])) {
                        $entity = $storage->load($ref['target_id']);
                    }

                    if ($entity) {
                        $entities[] = $entity;
                    }
                }
            }

            $options['subscribe_only_to'] = [
                '#type' => 'entity_autocomplete',
                // The textfield component that the autocomplete inherits from sets this to 128 by default. We have no
                // restriction, so we set this to a very high number that can allow 100 terms.
                '#maxlength' => 4096,
                '#size' => 30,
                '#target_type' => $type,
                '#tags' => true,
                '#selection_settings' => [
                    'target_bundles' => $bundles,
                ],
                '#title' => 'Subscribe only to',
                '#disabled' => $disabled,
                '#description' => $disabled ? $this->t('Value provided via settings.php.') : '',
                '#default_value' => $entities,
            ];
        }

        return $options;
    }

    /**
     * {@inheritdoc}
     */
    public function validateHandlerSettings(array &$form, FormStateInterface $form_state, $settings_key, $current_values)
    {
        if (!$this->shouldPushReferencedEntities() && !$this->shouldEmbedReferencedEntities()) {
            return;
        }

        $reference_types = $this->getReferencedEntityTypes();

        foreach ($current_values['sync_entities'] as $key => $config) {
            // Ignore field definitions.
            if (1 != substr_count($key, '-')) {
                continue;
            }

            // Ignore ignored configs.
            if (Flow::HANDLER_IGNORE == $config['handler']) {
                continue;
            }

            list($entity_type_id) = explode('-', $key);
            $index = array_search($entity_type_id, $reference_types);

            // Ignore configs that don't match our entity type.
            if (false === $index) {
                continue;
            }

            // One has an push handler, so we can ignore this.
            unset($reference_types[$index]);
        }

        if (!count($reference_types)) {
            return;
        }

        // We are just about to load this element, so we don't have any form element available yet. Validation will be
        // triggered again when the form is submitted.
        if (empty($form[$this->entityTypeName][$this->bundleName]['fields'][$settings_key]['handler'])) {
            return;
        }

        // No fitting handler was found- inform the user that he's missing some
        // configuration.
        if ($this->forcePushingReferencedEntities() || $this->forceEmbeddingReferencedEntities()) {
            $element = &$form[$this->entityTypeName][$this->bundleName]['fields'][$settings_key]['handler'];
        } else {
            $element = &$form[$this->entityTypeName][$this->bundleName]['fields'][$settings_key]['handler_settings']['export_referenced_entities'];
        }

        foreach ($reference_types as $type) {
            $form_state->setError(
                $element,
                t(
                    'You want to push %referenced\'s that are referenced in %source automatically, but you have not defined any handler for this entity type. Please scroll to the bundles of this entity type, add a handler and set "push" to "referenced" there.',
                    ['%referenced' => $type, '%source' => $settings_key]
                )
            );
        }
    }

    /**
     * @param $fieldDefinition
     *
     * @return array
     */
    public static function getReferencedEntityTypesFromFieldDefinition(FieldDefinitionInterface $fieldDefinition)
    {
        if ('dynamic_entity_reference' == $fieldDefinition->getFieldStorageDefinition()->getType()) {
            if ($fieldDefinition->getFieldStorageDefinition()->getSetting('exclude_entity_types')) {
                $entity_types = EntityHandlerPluginManager::getEntityTypes();

                $included = [];
                $excluded = $fieldDefinition->getFieldStorageDefinition()->getSetting('entity_type_ids');
                foreach ($entity_types as $entity_type) {
                    if (!in_array($entity_type['entity_type'], $excluded)) {
                        $included[] = $entity_type['entity_type'];
                    }
                }

                return $included;
            }

            return $fieldDefinition->getFieldStorageDefinition()->getSetting('entity_type_ids');
        }

        $reference_type = $fieldDefinition
            ->getFieldStorageDefinition()
            ->getPropertyDefinition('entity')
            ->getTargetDefinition()
            ->getEntityTypeId();

        return [$reference_type];
    }

    /**
     * {@inheritdoc}
     */
    public function pull(PullIntent $intent)
    {
        $action = $intent->getAction();

        // Deletion doesn't require any action on field basis for static data.
        if (SyncIntent::ACTION_DELETE == $action) {
            return false;
        }

        return $this->setValues($intent);
    }

    /**
     * {@inheritdoc}
     */
    public function push(PushIntent $intent)
    {
        $action = $intent->getAction();
        /**
         * @var \Drupal\Core\Entity\EntityInterface $entity
         */
        $entity = $intent->getEntity();

        // Deletion doesn't require any action on field basis for static data.
        if (SyncIntent::ACTION_DELETE == $action) {
            return false;
        }

        $data = $entity->get($this->fieldName)->getValue();

        $result = [];

        foreach ($data as $delta => $value) {
            $reference = $this->loadReferencedEntityFromFieldValue($value);

            if (!$reference || $reference->uuid() == $intent->getUuid()) {
                continue;
            }

            unset($value['target_id']);

            $result[] = $this->serializeReference($intent, $reference, $value);
        }

        $intent->setProperty($this->fieldName, $result);

        return true;
    }

    /**
     * Don't expose option, but force push.
     *
     * @return bool
     */
    protected function forcePushingReferencedEntities()
    {
        return false;
    }

    /**
     * Don't expose option, but force push.
     *
     * @return bool
     */
    protected function forceEmbeddingReferencedEntities()
    {
        return false;
    }

    /**
     * Check if referenced entities should be embedded automatically.
     *
     * @param bool $default
     *                      Whether to get the default value (TRUE) if none is set
     *                      yet
     *
     * @return bool
     */
    protected function shouldEmbedReferencedEntities($default = false)
    {
        if ($this->forceEmbeddingReferencedEntities()) {
            return true;
        }

        if (isset($this->settings['handler_settings']['embed_referenced_entities'])) {
            return (bool) $this->settings['handler_settings']['embed_referenced_entities'];
        }

        if ($default) {
            return true;
        }

        return false;
    }

    /**
     * Check if referenced entities should be pushed automatically.
     *
     * @param bool $default
     *                      Whether to get the default value (TRUE) if none is set
     *                      yet
     *
     * @return bool
     */
    protected function shouldPushReferencedEntities($default = false)
    {
        // Not syndicating views.
        $getReferencedEntityTypes = $this->getReferencedEntityTypes();
        if (in_array('view', $getReferencedEntityTypes)) {
            return false;
        }

        if ($this->forcePushingReferencedEntities()) {
            return true;
        }

        if (isset($this->settings['handler_settings']['export_referenced_entities'])) {
            return (bool) $this->settings['handler_settings']['export_referenced_entities'];
        }

        if ($default) {
            return true;
        }

        return false;
    }

    /**
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     *
     * @return bool
     */
    protected function allowPushingReferencedEntities()
    {
        $referenced_entity_types = \Drupal::entityTypeManager()->getStorage($this->getReferencedEntityTypes());
        foreach ($referenced_entity_types as $referenced_entity_type) {
            if ($referenced_entity_type instanceof ConfigEntityStorage) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    protected function allowSubscribeFilter()
    {
        return false;
    }

    /**
     * @return string[]
     */
    protected function getReferencedEntityTypes()
    {
        return self::getReferencedEntityTypesFromFieldDefinition($this->fieldDefinition);
    }

    /**
     * Load the entity that is either referenced or embedded by $definition.
     *
     * @param $definition
     *
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\Core\Entity\EntityStorageException
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     *
     * @return \Drupal\Core\Entity\EntityInterface
     */
    protected function loadReferencedEntity(PullIntent $intent, $definition)
    {
        return $intent->loadEmbeddedEntity($definition);
    }

    /**
     * {@inheritdoc}
     */
    protected function setValues(PullIntent $intent)
    {
        if ($intent->shouldMergeChanges() && !$this->forceMergeOverwrite()) {
            return false;
        }
        /**
         * @var \Drupal\Core\Entity\EntityInterface $entity
         */
        $entity = $intent->getEntity();

        $data = $intent->getProperty($this->fieldName);

        $values = [];
        foreach ($data ? $data : [] as $value) {
            $reference = $this->loadReferencedEntity($intent, $value);

            if ($reference) {
                $info = $intent->getEmbeddedEntityData($value);

                $attributes = $this->getFieldValuesForReference($reference, $intent);

                if (is_array($attributes)) {
                    $values[] = array_merge($info, $attributes);
                } else {
                    $values[] = $attributes;
                }
            } elseif (!$this->shouldEmbedReferencedEntities()) {
                // Shortcut: If it's just one value and a normal entity_reference field, the MissingDependencyManager will
                // directly update the field value of the entity and save it. Otherwise it will request a full pull of the
                // entity. So this saves some performance for simple references.
                if ('entity_reference' === $this->fieldDefinition->getType() && !$this->fieldDefinition->getFieldStorageDefinition()->isMultiple()) {
                    $intent->saveUnresolvedDependency($value, $this->fieldName);
                } else {
                    $intent->saveUnresolvedDependency($value);
                }
            }
        }

        $entity->set($this->fieldName, $values);

        return true;
    }

    /**
     * Get the values to be set to the $entity->field_*.
     *
     * @param $reference
     * @param $intent
     *
     * @return array
     */
    protected function getFieldValuesForReference($reference, $intent)
    {
        if ('entity_reference_revisions' == $this->fieldDefinition->getType()) {
            $attributes = [
                'target_id' => $reference->id(),
                'target_revision_id' => $reference->getRevisionId(),
            ];
        } else {
            $attributes = [
                'target_id' => $reference->id(),
            ];
        }

        return $attributes;
    }

    /**
     * Load the referenced entity, given the $entity->field_* value.
     *
     * @param $value
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     *
     * @return null|\Drupal\Core\Entity\EntityInterface
     */
    protected function loadReferencedEntityFromFieldValue($value)
    {
        if (empty($value['target_id'])) {
            return null;
        }

        $entityTypeManager = \Drupal::entityTypeManager();
        $reference_type = isset($value['target_type']) ? $value['target_type'] : $this->getReferencedEntityTypes()[0];

        $storage = $entityTypeManager
            ->getStorage($reference_type);

        $target_id = $value['target_id'];

        return $storage
            ->load($target_id);
    }

    /**
     * @return string[]
     */
    protected function getInvalidSubfields()
    {
        return [];
    }

    /**
     * @param $value
     *
     * @throws \Drupal\Core\Entity\EntityStorageException
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * @return array|object
     */
    protected function serializeReference(PushIntent $intent, EntityInterface $reference, $value)
    {
        foreach ($this->getInvalidSubfields() as $field) {
            unset($value[$field]);
        }
        foreach ($value as $key => $data) {
            if ('field_' == substr($key, 0, 6)) {
                unset($value[$key]);
            }
        }

        // Allow mapping by label.
        if ('taxonomy_term' == $reference->getEntityTypeId()) {
            $value[Entity::LABEL_KEY] = $reference->label();
        }

        if ($this->shouldEmbedReferencedEntities()) {
            return $intent->embed($reference, $value);
        }
        if ($this->shouldPushReferencedEntities()) {
            return $intent->addDependency($reference, $value);
        }

        return $intent->addReference(
            $reference,
            $value
        );
    }
}
