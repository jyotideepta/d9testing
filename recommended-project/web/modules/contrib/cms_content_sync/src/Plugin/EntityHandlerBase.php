<?php

namespace Drupal\cms_content_sync\Plugin;

use Drupal\cms_content_sync\Controller\Migration;
use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Event\BeforeEntityPull;
use Drupal\cms_content_sync\Event\BeforeEntityPush;
use Drupal\cms_content_sync\Exception\SyncException;
use Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableEntityBundleInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Render\RenderContext;
use Drupal\crop\Entity\Crop;
use Drupal\menu_link_content\Plugin\Menu\MenuLinkContent;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Common base class for entity handler plugins.
 *
 * @see \Drupal\cms_content_sync\Annotation\EntityHandler
 * @see \Drupal\cms_content_sync\Plugin\EntityHandlerInterface
 * @see plugin_api
 *
 * @ingroup third_party
 */
abstract class EntityHandlerBase extends PluginBase implements ContainerFactoryPluginInterface, EntityHandlerInterface
{
    public const USER_PROPERTY = null;
    public const USER_REVISION_PROPERTY = null;
    public const REVISION_TRANSLATION_AFFECTED_PROPERTY = null;

    /**
     * A logger instance.
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    protected $entityTypeName;
    protected $bundleName;
    protected $settings;

    /**
     * A sync instance.
     *
     * @var \Drupal\cms_content_sync\Entity\Flow
     */
    protected $flow;

    /**
     * Constructs a Drupal\rest\Plugin\ResourceBase object.
     *
     * @param array                    $configuration
     *                                                    A configuration array containing information about the plugin instance
     * @param string                   $plugin_id
     *                                                    The plugin_id for the plugin instance
     * @param mixed                    $plugin_definition
     *                                                    The plugin implementation definition
     * @param \Psr\Log\LoggerInterface $logger
     *                                                    A logger instance
     */
    public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger)
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->logger = $logger;
        $this->entityTypeName = $configuration['entity_type_name'];
        $this->bundleName = $configuration['bundle_name'];
        $this->settings = $configuration['settings'];
        $this->flow = $configuration['sync'];
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('cms_content_sync')
    );
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedPushOptions()
    {
        return [
            PushIntent::PUSH_DISABLED,
            PushIntent::PUSH_AUTOMATICALLY,
            PushIntent::PUSH_AS_DEPENDENCY,
            PushIntent::PUSH_MANUALLY,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedPullOptions()
    {
        return [
            PullIntent::PULL_DISABLED,
            PullIntent::PULL_MANUALLY,
            PullIntent::PULL_AUTOMATICALLY,
            PullIntent::PULL_AS_DEPENDENCY,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function updateEntityTypeDefinition(&$definition)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getHandlerSettings($current_values, $type = 'both')
    {
        $options = [];

        $no_menu_link_push = [
            'brick',
            'field_collection_item',
            'menu_link_content',
            'paragraph',
        ];

        if (!in_array($this->entityTypeName, $no_menu_link_push) && 'pull' !== $type) {
            $options['export_menu_items'] = [
                '#type' => 'checkbox',
                '#title' => 'Push menu items',
                '#default_value' => isset($current_values['export_menu_items']) && 0 === $current_values['export_menu_items'] ? 0 : 1,
            ];
        }

        return $options;
    }

    /**
     * {@inheritdoc}
     */
    public function validateHandlerSettings(array &$form, FormStateInterface $form_state, $settings_key, $current_values)
    {
        // No settings means no validation.
    }

    /**
     * Pull the remote entity.
     *
     * {@inheritdoc}
     */
    public function pull(PullIntent $intent)
    {
        $action = $intent->getAction();

        if ($this->ignorePull($intent)) {
            return false;
        }

        /**
         * @var \Drupal\Core\Entity\EntityInterface $entity
         */
        $entity = $intent->getEntity();

        if (SyncIntent::ACTION_DELETE == $action) {
            if ($entity) {
                return $this->deleteEntity($entity);
            }
            // Already done means success.
            if ($intent->getEntityStatus()->isDeleted()) {
                return true;
            }

            return false;
        }

        if ($entity) {
            if ($bundle_entity_type = $entity->getEntityType()->getBundleEntityType()) {
                $bundle_entity_type = \Drupal::entityTypeManager()->getStorage($bundle_entity_type)->load($entity->bundle());
                if (($bundle_entity_type instanceof RevisionableEntityBundleInterface && $bundle_entity_type->shouldCreateNewRevision()) || 'field_collection' == $bundle_entity_type->getEntityTypeId()) {
                    $entity->setNewRevision(true);
                }
            }
        } else {
            $entity = $this->createNew($intent);

            if (!$entity) {
                throw new SyncException(SyncException::CODE_ENTITY_API_FAILURE);
            }

            $intent->setEntity($entity);
        }

        if ($entity instanceof FieldableEntityInterface && !$this->setEntityValues($intent)) {
            return false;
        }

        // Allow other modules to extend the EntityHandlerBase pull.
        // Dispatch ExtendEntityPull.
        \Drupal::service('event_dispatcher')->dispatch(BeforeEntityPull::EVENT_NAME, new BeforeEntityPull($entity, $intent));

        return true;
    }

    /**
     * @param \Drupal\cms_content_sync\PushIntent $intent
     *
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     *
     * @return string
     */
    public function getViewUrl(EntityInterface $entity)
    {
        if (!$entity->hasLinkTemplate('canonical')) {
            throw new SyncException('No canonical link template found for entity '.$entity->getEntityTypeId().'.'.$entity->bundle().' '.$entity->id().'. Please overwrite the handler to provide a URL.');
        }

        try {
            return $entity->toUrl('canonical', ['absolute' => true])
                ->toString(true)
                ->getGeneratedUrl();
        } catch (\Exception $e) {
            throw new SyncException(SyncException::CODE_UNEXPECTED_EXCEPTION, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getForbiddenFields()
    {
        /**
         * @var \Drupal\Core\Entity\EntityTypeInterface $entity_type_entity
         */
        $entity_type_entity = \Drupal::service('entity_type.manager')
            ->getStorage($this->entityTypeName)
            ->getEntityType();

        return [
            // These basic fields are already taken care of, so we ignore them
            // here.
            $entity_type_entity->getKey('id'),
            $entity_type_entity->getKey('revision'),
            $entity_type_entity->getKey('bundle'),
            $entity_type_entity->getKey('uuid'),
            $entity_type_entity->getKey('label'),
            // These are not relevant or misleading when synchronized.
            'revision_default',
            'revision_translation_affected',
            'content_translation_outdated',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function push(PushIntent $intent, EntityInterface $entity = null)
    {
        if ($this->ignorePush($intent)) {
            return false;
        }

        if (!$entity) {
            $entity = $intent->getEntity();
        }

        // Base info.
        $name = $this->getEntityName($entity, $intent);
        // Focal point for example has no label.
        if (!$name) {
            $name = 'Unnamed '.$entity->getEntityTypeId().'.'.$entity->bundle();
        }
        $intent->getOperation()->setName($name, $intent->getActiveLanguage());

        // Menu items.
        if ($this->pushReferencedMenuItems()) {
            $menu_link_manager = \Drupal::service('plugin.manager.menu.link');
            /**
             * @var \Drupal\Core\Menu\MenuLinkManager $menu_link_manager
             */
            $menu_items = $menu_link_manager->loadLinksByRoute('entity.'.$this->entityTypeName.'.canonical', [$this->entityTypeName => $entity->id()]);
            $values = [];

            $form_values = _cms_content_sync_submit_cache($entity->getEntityTypeId(), $entity->uuid());

            foreach ($menu_items as $menu_item) {
                if (!($menu_item instanceof MenuLinkContent)) {
                    continue;
                }

                /**
                 * @var \Drupal\menu_link_content\Entity\MenuLinkContent $item
                 */
                $item = \Drupal::service('entity.repository')
                    ->loadEntityByUuid('menu_link_content', $menu_item->getDerivativeId());
                if (!$item) {
                    continue;
                }

                // Menu item has just been disabled => Ignore push in this case.
                if (isset($form_values['menu']) && $form_values['menu']['id'] == 'menu_link_content:'.$item->uuid()) {
                    if (!$form_values['menu']['enabled']) {
                        continue;
                    }
                }

                $details = [];
                $details['enabled'] = $item->get('enabled')->value;

                $values[] = $intent->addDependency($item, $details);
            }

            $intent->setProperty('menu_items', $values);
        }

        // Preview.
        $view_mode = $this->flow->getPreviewType($entity->getEntityTypeId(), $entity->bundle());
        if (Flow::PREVIEW_DISABLED != $view_mode) {
            $entityTypeManager = \Drupal::entityTypeManager();
            $view_builder = $entityTypeManager->getViewBuilder($this->entityTypeName);

            $preview = $view_builder->view($entity, $view_mode);
            $rendered = \Drupal::service('renderer');
            $html = $rendered->executeInRenderContext(
                new RenderContext(),
                function () use ($rendered, $preview) {
                    return $rendered->render($preview);
                }
            );
            $this->setPreviewHtml($html, $intent);
        }

        // Source URL.
        $intent->getOperation()->setSourceDeepLink($this->getViewUrl($entity), $intent->getActiveLanguage());

        // Fields.
        if ($entity instanceof FieldableEntityInterface) {
            /** @var \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager */
            $entityFieldManager = \Drupal::service('entity_field.manager');
            $type = $entity->getEntityTypeId();
            $bundle = $entity->bundle();
            $field_definitions = $entityFieldManager->getFieldDefinitions($type, $bundle);

            foreach ($field_definitions as $key => $field) {
                $handler = $this->flow->getFieldHandler($type, $bundle, $key);

                if (!$handler) {
                    continue;
                }

                $handler->push($intent);
            }
        }

        // Translations.
        if (!$intent->getActiveLanguage()
      && $this->isEntityTypeTranslatable($entity)) {
            $languages = array_keys($entity->getTranslationLanguages(false));

            foreach ($languages as $language) {
                $intent->changeTranslationLanguage($language);
                /**
                 * @var \Drupal\Core\Entity\FieldableEntityInterface $translation
                 */
                $translation = $entity->getTranslation($language);
                $this->push($intent, $translation);
            }

            $intent->changeTranslationLanguage();
        }

        // Allow other modules to extend the EntityHandlerBase push.
        // Dispatch entity push event.
        \Drupal::service('event_dispatcher')->dispatch(BeforeEntityPush::EVENT_NAME, new BeforeEntityPush($entity, $intent));

        return true;
    }

    /**
     * Whether or not menu item references should be pushed.
     *
     * @return bool
     */
    protected function pushReferencedMenuItems()
    {
        if (!isset($this->settings['handler_settings']['export_menu_items'])) {
            return true;
        }

        return 0 !== $this->settings['handler_settings']['export_menu_items'];
    }

    /**
     * Check if the pull should be ignored.
     *
     * @return bool
     *              Whether or not to ignore this pull request
     */
    protected function ignorePull(PullIntent $intent)
    {
        $reason = $intent->getReason();
        $action = $intent->getAction();

        if (PullIntent::PULL_AUTOMATICALLY == $reason) {
            if (PullIntent::PULL_MANUALLY == $this->settings['import']) {
                // Once pulled manually, updates will arrive automatically.
                if ((PullIntent::PULL_AUTOMATICALLY != $reason || PullIntent::PULL_MANUALLY != $this->settings['import']) || SyncIntent::ACTION_CREATE == $action) {
                    return true;
                }
            }
        }

        if (SyncIntent::ACTION_UPDATE == $action) {
            $behavior = $this->settings['import_updates'];
            if (PullIntent::PULL_UPDATE_IGNORE == $behavior) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether the entity type supports having a label.
     *
     * @return bool
     */
    protected function hasLabelProperty()
    {
        return true;
    }

    /**
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     *
     * @return \Drupal\Core\Entity\EntityInterface
     */
    protected function createNew(PullIntent $intent)
    {
        $entity_type = \Drupal::entityTypeManager()->getDefinition($intent->getEntityType());

        $base_data = [];

        if (EntityHandlerPluginManager::isEntityTypeConfiguration($intent->getEntityType())) {
            $base_data['id'] = $intent->getId();
        }

        if ($this->hasLabelProperty()) {
            $base_data[$entity_type->getKey('label')] = $intent->getOperation()->getName();
        }

        // Required as field collections share the same property for label and bundle.
        $base_data[$entity_type->getKey('bundle')] = $intent->getBundle();

        $base_data[$entity_type->getKey('uuid')] = $intent->getUuid();
        if ($entity_type->getKey('langcode')) {
            $base_data[$entity_type->getKey('langcode')] = $intent->getProperty($entity_type->getKey('langcode'));
        }

        $storage = \Drupal::entityTypeManager()->getStorage($intent->getEntityType());

        return $storage->create($base_data);
    }

    /**
     * Delete a entity.
     *
     * @param \Drupal\Core\Entity\EntityInterface $entity
     *                                                    The entity to delete
     *
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     *
     * @return bool
     *              Returns TRUE or FALSE for the deletion process
     */
    protected function deleteEntity(EntityInterface $entity)
    {
        try {
            $entity->delete();
        } catch (\Exception $e) {
            throw new SyncException(SyncException::CODE_ENTITY_API_FAILURE, $e);
        }

        return true;
    }

    /**
     * @param \Drupal\Core\Entity\EntityInterface $entity
     * @param \Drupal\cms_content_sync\PullIntent $intent
     *
     * @throws \Drupal\Core\Entity\EntityStorageException
     */
    protected function saveEntity($entity, $intent)
    {
        $entity->save();
    }

    /**
     * Set the values for the pulled entity.
     *
     * @param \Drupal\cms_content_sync\SyncIntent          $intent
     * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
     *                                                             The translation of the entity
     *
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     *
     * @return bool
     *              Returns TRUE when the values are set
     *
     * @see Flow::PULL_*
     */
    protected function setEntityValues(PullIntent $intent, FieldableEntityInterface $entity = null)
    {
        if (!$entity) {
            $entity = $intent->getEntity();
        }

        /** @var \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager */
        $entityFieldManager = \Drupal::service('entity_field.manager');
        $type = $entity->getEntityTypeId();
        $bundle = $entity->bundle();
        $field_definitions = $entityFieldManager->getFieldDefinitions($type, $bundle);

        $entity_type = \Drupal::entityTypeManager()->getDefinition($intent->getEntityType());
        $label = $entity_type->getKey('label');
        if ($label && !$intent->shouldMergeChanges() && $this->hasLabelProperty()) {
            $entity->set($label, $intent->getOperation()->getName($intent->getActiveLanguage()));
        }

        $static_fields = $this->getStaticFields();

        $is_translatable = $this->isEntityTypeTranslatable($entity);
        $is_translation = boolval($intent->getActiveLanguage());

        $user = \Drupal::currentUser();
        if (static::USER_PROPERTY && $entity->hasField(static::USER_PROPERTY) && !$intent->getEntityStatus()->isOverriddenLocally()) {
            $entity->set(static::USER_PROPERTY, ['target_id' => $user->id()]);
        }
        if (static::USER_REVISION_PROPERTY && $entity->hasField(static::USER_REVISION_PROPERTY)) {
            $entity->set(static::USER_REVISION_PROPERTY, ['target_id' => $user->id()]);
        }
        if (static::REVISION_TRANSLATION_AFFECTED_PROPERTY && $entity->hasField(static::REVISION_TRANSLATION_AFFECTED_PROPERTY)) {
            $entity->set(static::REVISION_TRANSLATION_AFFECTED_PROPERTY, 1);
        }

        foreach ($field_definitions as $key => $field) {
            $handler = $this->flow->getFieldHandler($type, $bundle, $key);

            if (!$handler) {
                continue;
            }

            // This field cannot be updated.
            if (in_array($key, $static_fields) && SyncIntent::ACTION_CREATE != $intent->getAction()) {
                continue;
            }

            if ($is_translatable && $is_translation && !$field->isTranslatable()) {
                continue;
            }

            if ('image' == $field->getType() || 'file' == $field->getType()) {
                // Focal Point takes information from the image field directly
                // so we have to set it before the entity is saved the first time.
                $data = $intent->getProperty($key);
                if (null === $data) {
                    $data = [];
                }
                foreach ($data as &$value) {
                    /**
                     * @var \Drupal\file\Entity\File $file
                     */
                    $file = $intent->loadEmbeddedEntity($value);
                    if ($file) {
                        if ('image' == $field->getType()) {
                            $moduleHandler = \Drupal::service('module_handler');
                            if ($moduleHandler->moduleExists('crop') && $moduleHandler->moduleExists('focal_point')) {
                                /**
                                 * @var \Drupal\crop\Entity\Crop $crop
                                 */
                                $crop = Crop::findCrop($file->getFileUri(), 'focal_point');
                                if ($crop) {
                                    $position = $crop->position();

                                    // Convert absolute to relative.
                                    $size = getimagesize($file->getFileUri());
                                    $value['focal_point'] = ($position['x'] / $size[0] * 100).','.($position['y'] / $size[1] * 100);
                                }
                            }
                        }
                    }
                }

                $intent->overwriteProperty($key, $data);

                continue;
            }

            $handler->pull($intent);
        }

        if (PullIntent::PULL_UPDATE_UNPUBLISHED === $this->flow->getEntityTypeConfig($this->entityTypeName, $this->bundleName)['import_updates']) {
            if ($entity instanceof NodeInterface) {
                if ($entity->id()) {
                    $entity->isDefaultRevision(false);
                } else {
                    $entity->setPublished(false);
                }
            }
        }

        try {
            $this->saveEntity($entity, $intent);
        } catch (\Exception $e) {
            throw new SyncException(SyncException::CODE_ENTITY_API_FAILURE, $e);
        }

        // We can't set file fields until the source entity has been saved.
        // Otherwise Drupal will throw Exceptions:
        // Error message is: InvalidArgumentException: Invalid translation language (und) specified.
        // Occurs when using translatable entities referencing files.
        $changed = false;
        foreach ($field_definitions as $key => $field) {
            $handler = $this->flow->getFieldHandler($type, $bundle, $key);

            if (!$handler) {
                continue;
            }

            // This field cannot be updated.
            if (in_array($key, $static_fields) && SyncIntent::ACTION_CREATE != $intent->getAction()) {
                continue;
            }

            if ($is_translatable && $is_translation && !$field->isTranslatable()) {
                continue;
            }

            if ('image' != $field->getType() && 'file' != $field->getType()) {
                continue;
            }

            $handler->pull($intent);
            $changed = true;
        }

        if (!$intent->getActiveLanguage()) {
            $created = $this->getDateProperty($intent, 'created');
            // See https://www.drupal.org/project/drupal/issues/2833378
            if ($created && method_exists($entity, 'getCreatedTime') && method_exists($entity, 'setCreatedTime')) {
                if ($created !== $entity->getCreatedTime()) {
                    $entity->setCreatedTime($created);
                    $changed = true;
                }
            }
            if ($entity instanceof EntityChangedInterface) {
                $entity->setChangedTime(time());
                $changed = true;
            }
        }

        if ($changed) {
            try {
                $this->saveEntity($entity, $intent);
            } catch (\Exception $e) {
                throw new SyncException(SyncException::CODE_ENTITY_API_FAILURE, $e);
            }
        }

        if ($is_translatable && !$intent->getActiveLanguage()) {
            $languages = $intent->getTranslationLanguages();
            foreach ($languages as $language) {
                /**
                 * If the provided entity is fieldable, translations are as well.
                 *
                 * @var \Drupal\Core\Entity\FieldableEntityInterface $translation
                 */
                if ($entity->hasTranslation($language)) {
                    $translation = $entity->getTranslation($language);
                } else {
                    $translation = $entity->addTranslation($language);
                }

                $intent->changeTranslationLanguage($language);
                if (!$this->ignorePull($intent)) {
                    $this->setEntityValues($intent, $translation);
                }
            }

            // Delete translations that were deleted on master site.
            if (boolval($this->settings['import_deletion_settings']['import_deletion'])) {
                $existing = $entity->getTranslationLanguages(false);
                foreach ($existing as &$language) {
                    $language = $language->getId();
                }
                $languages = array_diff($existing, $languages);
                if (count($languages)) {
                    foreach ($languages as $language) {
                        $entity->removeTranslation($language);
                    }

                    try {
                        $this->saveEntity($entity, $intent);
                    } catch (\Exception $e) {
                        throw new SyncException(SyncException::CODE_ENTITY_API_FAILURE, $e);
                    }
                }
            }

            $intent->changeTranslationLanguage();
        }

        return true;
    }

    protected function setDateProperty(SyncIntent $intent, string $name, int $timestamp)
    {
        if (Migration::useV2()) {
            $intent->setProperty($name, ['value' => $timestamp]);
        } else {
            $intent->setProperty($name, $timestamp);
        }
    }

    protected function getDateProperty(SyncIntent $intent, string $name)
    {
        $value = $intent->getProperty($name);
        if (is_array($value)) {
            return isset($value[0]['value']) ? $value[0]['value'] : $value['value'];
        }

        return (int) $value;
    }

    /**
     * Check if the entity should not be ignored from the push.
     *
     * @param \Drupal\cms_content_sync\SyncIntent          $intent
     *                                                             The Sync Core Request
     * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
     *                                                             The entity that could be ignored
     * @param string                                       $reason
     *                                                             The reason why the entity should be ignored from the push
     * @param string                                       $action
     *                                                             The action to apply
     *
     * @throws \Exception
     *
     * @return bool
     *              Whether or not to ignore this push request
     */
    protected function ignorePush(PushIntent $intent)
    {
        $reason = $intent->getReason();
        $action = $intent->getAction();

        if (PushIntent::PUSH_AUTOMATICALLY == $reason) {
            if (PushIntent::PUSH_MANUALLY == $this->settings['export']) {
                return true;
            }
        }

        if (SyncIntent::ACTION_UPDATE == $action) {
            foreach (EntityStatus::getInfosForEntity($intent->getEntityType(), $intent->getUuid()) as $info) {
                $flow = $info->getFlow();
                if (!$flow) {
                    continue;
                }
                if (!$info->getLastPull()) {
                    continue;
                }
                if (!$info->isSourceEntity()) {
                    break;
                }
                $config = $flow->getEntityTypeConfig($intent->getEntityType(), $intent->getBundle());

                if (PullIntent::PULL_UPDATE_FORCE_UNLESS_OVERRIDDEN == $config['import_updates']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get a list of fields that can't be updated.
     *
     * @return string[]
     */
    protected function getStaticFields()
    {
        return [];
    }

    protected function getEntityName(EntityInterface $entity, PushIntent $intent)
    {
        return $entity->label();
    }

    protected function setPreviewHtml($html, PushIntent $intent)
    {
        try {
            $intent->getOperation()->setPreviewHtml($html, $intent->getActiveLanguage());
        } catch (\Exception $error) {
            $entity = $intent->getEntity();

            $messenger = \Drupal::messenger();
            $messenger->addWarning(
                t(
                    'Failed to save preview for %label: %error',
                    [
                        '%error' => $error->getMessage(),
                        '%label' => $entity->label(),
                    ]
                )
            );

            \Drupal::logger('cms_content_sync')->error('Failed to save preview when pushing @type.@bundle @id @label: @error', [
                '@type' => $entity->getEntityTypeId(),
                '@bundle' => $entity->bundle(),
                '@id' => $entity->id(),
                '@label' => $entity->label(),
                '@error' => $error->getMessage(),
            ]);
        }
    }

    /**
     * @param \Drupal\Core\Entity\EntityInterface $entity
     *
     * @return bool
     */
    protected function isEntityTypeTranslatable($entity)
    {
        return $entity instanceof TranslatableInterface && $entity->getEntityType()->getKey('langcode');
    }
}
