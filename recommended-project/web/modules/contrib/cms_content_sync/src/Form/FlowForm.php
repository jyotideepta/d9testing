<?php

namespace Drupal\cms_content_sync\Form;

use Drupal\cms_content_sync\Controller\AuthenticationByUser;
use Drupal\cms_content_sync\Controller\ContentSyncSettings;
use Drupal\cms_content_sync\Controller\Migration;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager;
use Drupal\cms_content_sync\Plugin\Type\FieldHandlerPluginManager;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncCoreInterface\SyncCoreFactory;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Cache\CacheTagsInvalidator;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableEntityBundleInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;
use EdgeBox\SyncCore\Exception\BadRequestException;
use EdgeBox\SyncCore\Exception\ForbiddenException;
use EdgeBox\SyncCore\Exception\NotFoundException;
use EdgeBox\SyncCore\Exception\SyncCoreException;
use EdgeBox\SyncCore\Interfaces\IApplicationInterface;
use EdgeBox\SyncCore\V1\SyncCoreClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Form handler for the Flow add and edit forms.
 */
class FlowForm extends EntityForm
{
    /**
     * @var string cms_content_sync_PREVIEW_FIELD
     *             The name of the view mode that must be present to allow teaser previews
     */
    public const cms_content_sync_PREVIEW_FIELD = 'cms_content_sync_preview';

    /**
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    /**
     * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
     */
    protected $bundleInfoService;

    /**
     * @var \Drupal\Core\Entity\EntityFieldManager
     */
    protected $entityFieldManager;

    /**
     * @var \Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager
     */
    protected $entityPluginManager;

    /**
     * @var \Drupal\cms_content_sync\Plugin\Type\FieldHandlerPluginManager
     */
    protected $fieldPluginManager;

    /**
     * The Messenger service.
     *
     * @var \Drupal\Core\Messenger\MessengerInterface
     */
    protected $messenger;

    /**
     * The config factory to load configuration.
     *
     * @var \Drupal\Core\Config\ConfigFactory
     */
    protected $configFactory;

    /**
     * The Drupal Core Cache Tags Invalidator.
     *
     * @var \Drupal\Core\Cache\CacheTagsInvalidator
     */
    protected $cacheTagsInvalidator;

    /**
     * @var string
     */
    protected $triggeringType;

    /**
     * @var string
     */
    protected $triggeringBundle;

    /**
     * @var string
     */
    protected $triggeredAction;

    /**
     * @var string[][]
     */
    protected $ajaxReplaceElements = [];

    /**
     * Constructs an object.
     *
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface                  $entity_type_manager
     *                                                                                                The entity query
     * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface               $bundle_info_service
     *                                                                                                The bundle info service
     * @param \Drupal\Core\Entity\EntityFieldManager                          $entity_field_manager
     *                                                                                                The entity field manager
     * @param \Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager $entity_plugin_manager
     *                                                                                                The content sync entity manager
     * @param \Drupal\cms_content_sync\Plugin\Type\FieldHandlerPluginManager  $field_plugin_manager
     *                                                                                                The content sync field plugin manager
     * @param \Drupal\Core\Messenger\MessengerInterface                       $messenger
     *                                                                                                The messenger service
     * @param \Drupal\Core\Config\ConfigFactory                               $config_factory
     *                                                                                                The messenger service
     * @param \Drupal\Core\Cache\CacheTagsInvalidator                         $cache_tags_invalidator
     *                                                                                                The Drupal Core Cache Tags Invalidator
     */
    public function __construct(
        EntityTypeManagerInterface $entity_type_manager,
        EntityTypeBundleInfoInterface $bundle_info_service,
        EntityFieldManager $entity_field_manager,
        EntityHandlerPluginManager $entity_plugin_manager,
        FieldHandlerPluginManager $field_plugin_manager,
        MessengerInterface $messenger,
        ConfigFactory $config_factory,
        CacheTagsInvalidator $cache_tags_invalidator
    ) {
        $this->entityTypeManager = $entity_type_manager;
        $this->bundleInfoService = $bundle_info_service;
        $this->entityFieldManager = $entity_field_manager;
        $this->entityPluginManager = $entity_plugin_manager;
        $this->fieldPluginManager = $field_plugin_manager;
        $this->messenger = $messenger;
        $this->configFactory = $config_factory;
        $this->cacheTagsInvalidator = $cache_tags_invalidator;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.cms_content_sync_entity_handler'),
      $container->get('plugin.manager.cms_content_sync_field_handler'),
      $container->get('messenger'),
      $container->get('config.factory'),
      $container->get('cache_tags.invalidator')
    );
    }

    /**
     * A sync handler has been updated, so the options must be updated as well.
     * We're simply reloading the table in this case.
     *
     * @param array $form
     *
     * @return array
     *               The new sync_entities table
     */
    public function updateSyncHandler($form, FormStateInterface $form_state)
    {
        return $form['sync_entities'];
    }

    /**
     * {@inheritdoc}
     */
    public function form(array $form, FormStateInterface $form_state)
    {
        $type = $this->getCurrentFormType();

        // Add form library for some custom styling.
        $form['#attached']['library'][] = 'cms_content_sync/flow-form';

        if (!$type) {
            return $this->selectTypeForm($form, $form_state);
        }

        // Before a flow can be created, at least one pool must exist.
        // Get all pool entities.
        $pool_entities = Pool::getAll();

        if (empty($pool_entities)) {
            global $base_url;
            $path = Url::fromRoute('cms_content_sync.cms_content_sync_pool.pool_required')
                ->toString();
            $response = new RedirectResponse($base_url.$path);
            $response->send();
        }

        $form = parent::form($form, $form_state);
        $form['#tree'] = true;

        if ($this->shouldOpenAll($form_state)) {
            $form['open_all'] = [
                '#type' => 'hidden',
                '#value' => '1',
            ];
        }

        /**
         * @var \Drupal\cms_content_sync\Entity\Flow $flow
         */
        $flow = $this->entity;

        $form['name'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Name'),
            '#maxlength' => 255,
            '#attributes' => [
                'autofocus' => 'autofocus',
            ],
            '#default_value' => $flow->label(),
            '#description' => $this->t('An administrative name describing the workflow intended to be achieved with this synchronization.'),
            '#required' => true,
        ];

        $form['id'] = [
            '#type' => 'machine_name',
            '#default_value' => $flow->id(),
            '#machine_name' => [
                'exists' => [$this, 'exists'],
                'source' => ['name'],
            ],
            '#disabled' => !$flow->isNew(),
        ];

        $config_machine_name = $flow->id();
        if (!isset($config_machine_name)) {
            $config_machine_name = '<machine_name_of_the_configuration>';
        }

        $flow_id = $flow->id();
        if (isset($flow_id)) {
            $flow_id = 'cms_content_sync.flow.'.$flow_id;
            $non_overridden_config = $this->configFactory->get($flow_id)
                ->getRawData();
            $non_overridden_flow_status = isset($non_overridden_config['status']) ? $non_overridden_config['status'] : null;
        }

        $flow_status_description = '';
        $active_flow_status = $this->configFactory->get($flow_id)->get('status');
        if (isset($non_overridden_flow_status, $active_flow_status)) {
            if ($active_flow_status != $non_overridden_flow_status) {
                $flow_status_description = '<br><b>This value is overridden within the settings.php file.</b>';
            }
        }

        $form['status'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Active'),
            '#default_value' => isset($non_overridden_flow_status) ? $non_overridden_flow_status : true,
            '#description' => $this->t(
                'If the flow is not active, none of the below configured behaviors will take effect. This configuration could be overwritten within your environment specific settings.php file:<br> <i>@status_config</i>.'.$flow_status_description.'',
                [
                    '@status_config' => '$config["cms_content_sync.flow.'.$config_machine_name.'"]["status"] = FALSE;',
                ]
            ),
        ];

        $form['type'] = [
            '#title' => $this->t('Type'),
            '#markup' => '<br>'.$this->t('Right now the this Flow is set to @type. <strong>If you want to change it, first save all changes you made as otherwise they will be lost!</strong> Then click here: ', [
                '@type' => (Flow::TYPE_BOTH === $type ? $this->t('Push and Pull') : (Flow::TYPE_PULL === $type ? $this->t('Pull') : $this->t('Push'))),
            ]).
            '<a href="?type=">'.$this->t('Change').'</a><br><br>',
        ];

        $entity_types = $this->bundleInfoService->getAllBundleInfo();
        ksort($entity_types);

        // Remove the Content Sync Entity Status entity type form the array.
        unset($entity_types['cms_content_sync_entity_status']);

        $entity_type_list = [
            '#type' => 'vertical_tabs',
            '#default_tab' => 'edit-node',
            '#tree' => true,
        ];

        $form['entity_type_list'] = $entity_type_list;

        foreach ($entity_types as $type_key => $entity_type) {
            $this->renderEntityType($form, $form_state, $type_key);
        }

        $this->disableOverridenConfigs($form);

        return $form;
    }

    /**
     * Enable bundle / Disable bundle / Show fields => return individual form
     * element to replace.
     *
     * @param array $form
     *
     * @return array the bundle settings
     */
    public function ajaxReturn($form, FormStateInterface $form_state)
    {
        $type_key = $this->triggeringType;
        $entity_bundle_name = $this->triggeringBundle;

        if ('fields' === $this->triggeredAction) {
            return $form[$type_key][$entity_bundle_name]['fields'];
        }

        return $form[$type_key][$entity_bundle_name];
    }

    /**
     * Enable bundle => show settings..
     *
     * @param array $form
     */
    public function enableBundle($form, FormStateInterface $form_state)
    {
        $trigger = $form_state->getTriggeringElement();
        $type_key = $trigger['#entity_type'];
        $entity_bundle_name = $trigger['#bundle'];

        $this->triggeringType = $type_key;
        $this->triggeringBundle = $entity_bundle_name;
        $this->triggeredAction = 'enable';

        $this->fixMissingFormStateFromAjax($form, $form_state);

        $form_state->setValue([$type_key, $entity_bundle_name, 'edit'], '1');

        $form_state->setRebuild();
    }

    /**
     * Disable bundle => hide settings.
     *
     * @param array $form
     */
    public function disableBundle($form, FormStateInterface $form_state)
    {
        $trigger = $form_state->getTriggeringElement();
        $type_key = $trigger['#entity_type'];
        $entity_bundle_name = $trigger['#bundle'];

        $this->triggeringType = $type_key;
        $this->triggeringBundle = $entity_bundle_name;
        $this->triggeredAction = 'disable';

        $this->fixMissingFormStateFromAjax($form, $form_state);

        $form_state->setValue([$type_key, $entity_bundle_name, 'edit'], '0');
        $form_state->setValue([
            $type_key,
            $entity_bundle_name,
            'handler',
        ], 'ignore');

        $form_state->setRebuild();
    }

    /**
     * Show all field settings not just the summary.
     *
     * @param array $form
     */
    public function showAllFields($form, FormStateInterface $form_state)
    {
        $trigger = $form_state->getTriggeringElement();
        $type_key = $trigger['#entity_type'];
        $entity_bundle_name = $trigger['#bundle'];

        $this->triggeringType = $type_key;
        $this->triggeringBundle = $entity_bundle_name;
        $this->triggeredAction = 'fields';

        $this->fixMissingFormStateFromAjax($form, $form_state);

        $form_state->setValue([
            $type_key,
            $entity_bundle_name,
            'fields',
            'advanced',
            'show-all',
        ], '1');

        $form_state->setRebuild();
    }

    /**
     * Show version mismatches. We need to re-set the missing form state here so
     * the form doesn't break when using this button.
     *
     * @param array $form
     */
    public function showVersionMismatches($form, FormStateInterface $form_state)
    {
        $trigger = $form_state->getTriggeringElement();
        $type_key = $trigger['#entity_type'];
        $entity_bundle_name = $trigger['#bundle'];

        $this->triggeringType = $type_key;
        $this->triggeringBundle = $entity_bundle_name;
        $this->triggeredAction = 'enable';

        $this->fixMissingFormStateFromAjax($form, $form_state);

        $form_state->setValue([$type_key, $entity_bundle_name, 'edit'], '1');
        $form_state->setValue([
            $type_key,
            $entity_bundle_name,
            'show-version-mismatches',
        ], '1');

        $form_state->setRebuild();
    }

    /**
     * Show all field settings not just the summary.
     *
     * @param array $form
     */
    public function enableAllReferenced($form, FormStateInterface $form_state)
    {
        $trigger = $form_state->getTriggeringElement();
        $type_key = $trigger['#entity_type'];
        $entity_bundle_name = $trigger['#bundle'];

        $this->triggeringType = $type_key;
        $this->triggeringBundle = $entity_bundle_name;
        $this->triggeredAction = 'fields';

        $this->fixMissingFormStateFromAjax($form, $form_state);

        $referenced_type = $trigger['#referenced_type'];
        $referenced_bundles = $trigger['#referenced_bundles'];

        if ('all' === $referenced_bundles) {
            $entity_types = $this->bundleInfoService->getAllBundleInfo();

            foreach ($entity_types[$referenced_type] as $bundle => $set) {
                if (empty($current_values['sync_entities'][$referenced_type.'-'.$bundle]['handler']) || Flow::HANDLER_IGNORE == $current_values['sync_entities'][$referenced_type.'-'.$bundle]['handler']) {
                    $form_state->setValue([$referenced_type, $bundle, 'edit'], '1');

                    $this->ajaxReplaceElements[] = [$referenced_type, $bundle];
                }
            }
        } else {
            foreach ($referenced_bundles as $bundle) {
                if (empty($current_values['sync_entities'][$referenced_type.'-'.$bundle]['handler']) || Flow::HANDLER_IGNORE == $current_values['sync_entities'][$referenced_type.'-'.$bundle]['handler']) {
                    $form_state->setValue([$referenced_type, $bundle, 'edit'], '1');

                    $this->ajaxReplaceElements[] = [$referenced_type, $bundle];
                }
            }
        }

        $form_state->setRebuild();
    }

    /**
     * Enable all referenced => return multiple form elements to replace.
     *
     * @param array $form
     *
     * @return \Drupal\Core\Ajax\AjaxResponse AJAX commands to execute
     */
    public function enableAllReferencedReturn($form, FormStateInterface $form_state)
    {
        $response = new AjaxResponse();

        foreach ($this->ajaxReplaceElements as $keys) {
            $type_key = $keys[0];
            $entity_bundle_name = $keys[1];

            $bundle_id = $type_key.'-'.$entity_bundle_name;
            $settings_id = 'sync-entities-'.$bundle_id;

            $response->addCommand(new ReplaceCommand('#'.$settings_id, $form[$type_key][$entity_bundle_name]));
        }

        if ('fields' === $this->triggeredAction) {
            $type_key = $this->triggeringType;
            $entity_bundle_name = $this->triggeringBundle;

            $bundle_id = $type_key.'-'.$entity_bundle_name;
            $settings_id = 'sync-entities-'.$bundle_id;
            $field_settings_id = $settings_id.'-fields';

            $response->addCommand(new ReplaceCommand('#'.$field_settings_id, $form[$type_key][$entity_bundle_name]['fields']));
        }

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        $entity_types = [];
        $entity_types_with_fields = [];

        $current_values = $this->getCurrentValues($form_state);

        $used_pools = [];

        foreach ($current_values['sync_entities'] as $key => &$config) {
            if ('#' == $key[0]) {
                continue;
            }

            $count = substr_count($key, '-');
            if (0 == $count) {
                continue;
            }

            $values = $config;
            if (empty($values['handler']) || Flow::HANDLER_IGNORE == $values['handler']) {
                continue;
            }

            if (1 == $count) {
                list($entity_type, $bundle) = explode('-', $key);
                $entity_types[] = $entity_type.'-'.$bundle;
                $handler = $this->entityPluginManager->createInstance($values['handler'], [
                    'entity_type_name' => $entity_type,
                    'bundle_name' => $bundle,
                    'settings' => $values,
                    'sync' => null,
                ]);

                if (!empty($values['export']) && PushIntent::PUSH_DISABLED !== $values['export']) {
                    foreach ($values['export_pools'] as $name => $behavior) {
                        if (Pool::POOL_USAGE_FORBID !== $behavior) {
                            $used_pools[$name] = true;
                        }
                    }
                }

                if (!empty($values['import']) && PullIntent::PULL_DISABLED !== $values['import']) {
                    foreach ($values['import_pools'] as $name => $behavior) {
                        if (Pool::POOL_USAGE_FORBID !== $behavior) {
                            $used_pools[$name] = true;
                        }
                    }
                }
            } else {
                list($entity_type, $bundle, $field) = explode('-', $key);
                $entity_types_with_fields[] = $entity_type.'-'.$bundle;

                /**
                 * @var \Drupal\Core\Field\FieldDefinitionInterface[] $fields
                 */
                $field_definition = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle)[$field];
                $handler = $this->fieldPluginManager->createInstance($values['handler'], [
                    'entity_type_name' => $entity_type,
                    'bundle_name' => $bundle,
                    'field_name' => $field,
                    'field_definition' => $field_definition,
                    'settings' => $values,
                    'sync' => null,
                ]);
            }

            $handler->validateHandlerSettings($form, $form_state, $key, $current_values);
        }

        if (!Migration::alwaysUseV2()) {
            // Validate that only one Sync Core is used per Flow.
            $pools = Pool::getAll();
            $sync_core_url = null;
            foreach ($used_pools as $name => $used) {
                if (null === $sync_core_url) {
                    $sync_core_url = $pools[$name]->getSyncCoreUrl();
                } elseif ($sync_core_url !== $pools[$name]->getSyncCoreUrl()) {
                    $form_state->setErrorByName('', $this->t('You can only use Pools from one Sync Core per Flow.'));

                    break;
                }
            }

            $error = $this->validateSyncCoreAccessToSite($sync_core_url);

            if ($error) {
                if ($current_values['status']) {
                    $form_state->setErrorByName('name', $error);
                    \Drupal::messenger()
                        ->addWarning($this->t('To save this Flow anyway you can set it to inactive and try again.'));
                } else {
                    \Drupal::messenger()->addWarning($error);
                }
            }
        }

        return parent::validateForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function save(array $form, FormStateInterface $form_state)
    {
        $config = $this->entity;

        $config->{'sync_entities'} = $this->getCurrentValues($form_state)['sync_entities'];

        $sync_entities = &$config->{'sync_entities'};

        $export_menu_items_automatically = false;
        if (isset($sync_entities['menu_link_content-menu_link_content']) && PushIntent::PUSH_AUTOMATICALLY == $sync_entities['menu_link_content-menu_link_content']['export']) {
            $export_menu_items_automatically = true;
        }

        foreach ($sync_entities as $key => $settings) {
            // Entity settings.
            if (1 != substr_count($key, '-')) {
                continue;
            }

            preg_match('/^(.+)-(.+)$/', $key, $matches);

            $type_key = $matches[1];
            $bundle_key = $matches[2];

            $sync_entities[$key]['version'] = Flow::getEntityTypeVersion($type_key, $bundle_key);
            $sync_entities[$key]['entity_type_name'] = $type_key;
            $sync_entities[$key]['bundle_name'] = $bundle_key;

            // If the Flow should pull manually, the Pool selection must also be set to Manual. Otherwise the entities won't
            // show up in the pull dashboard. To protect people from that scenario, we're changing that automatically.
            if (PullIntent::PULL_MANUALLY === $settings['import']) {
                foreach ($settings['import_pools'] as $pool => $setting) {
                    if (Pool::POOL_USAGE_FORCE === $setting) {
                        $sync_entities[$key]['import_pools'][$pool] = Pool::POOL_USAGE_ALLOW;
                    }
                }
            }

            // If menu items should be exported automatically, the entity type option "export menu items"
            // have to be disabled to avoid a race condition.
            if (PushIntent::PUSH_DISABLED != $settings['export']) {
                if ($export_menu_items_automatically && isset($settings['handler_settings']['export_menu_items'])) {
                    $sync_entities[$key]['handler_settings']['export_menu_items'] = 0;
                }
            }
        }

        $status = $config->save();

        if ($status) {
            $this->messenger->addMessage($this->t('Saved the %label Flow.', [
                '%label' => $config->label(),
            ]));
        } else {
            $this->messenger->addMessage($this->t('The %label Flow could not be saved.', [
                '%label' => $config->label(),
            ]));
        }

        $triggering_element = $form_state->getTriggeringElement();
        if ('submit' == $triggering_element['#parents'][1]) {
            // Make sure that the export is executed.
            \Drupal::request()->query->remove('destination');
            $form_state->setRedirect('entity.cms_content_sync_flow.export', ['cms_content_sync_flow' => $config->id()]);
        } else {
            $form_state->setRedirect('entity.cms_content_sync_flow.collection');
        }

        $moduleHandler = \Drupal::service('module_handler');
        if ($moduleHandler->moduleExists('cms_content_sync_developer')) {
            $config_factory = $this->configFactory;
            $developer_config = $config_factory->getEditable('cms_content_sync.developer');
            $mismatching_versions = $developer_config->get('version_mismatch');
            if (!empty($mismatching_versions)) {
                unset($mismatching_versions[$config->id()]);
                $developer_config->set('version_mismatch', $mismatching_versions)
                    ->save();
            }
        }

        // Invalidate the admin menu cache to ensure the Content Dashboard Menu gets shown or hidden.
        $this->cacheTagsInvalidator->invalidateTags(['config:system.menu.admin']);
    }

    /**
     * Check if the entity exists.
     *
     * A helper function to check whether an
     * Flow configuration entity exists.
     *
     * @param int $id
     *                An ID of sync
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     *
     * @return bool
     *              Checking on exist an entity
     */
    public function exists($id)
    {
        $entity = $this->entityTypeManager
            ->getStorage('cms_content_sync_flow')
            ->getQuery()
            ->condition('id', $id)
            ->execute();

        return (bool) $entity;
    }

    protected function getCurrentFormType()
    {
        if (isset($_GET['type'])) {
            if (in_array($_GET['type'], [
                Flow::TYPE_PULL,
                Flow::TYPE_PUSH,
                Flow::TYPE_BOTH,
            ])) {
                return $_GET['type'];
            }

            return null;
        }

        /**
         * @var \Drupal\cms_content_sync\Entity\Flow $flow
         */
        $flow = $this->entity;

        return $flow->getType();
    }

    /**
     * If nothing has been configured yet, let the user chose whether to push and
     * pull with this flow. This will make the flow form significantly smaller
     * and simpler to manage.
     *
     * @return array
     */
    protected function selectTypeForm(array $form, FormStateInterface $form_state)
    {
        $form['type'] = [
            '#title' => $this->t('Type'),
            '#markup' => $this->t('Please select the type of Flow you want to create.').'<br><br>'.
            $this->t('For content staging select "push" on your stage site and "pull" on your production site.').'<br><br>'.
            '<ul class="action-links">'.
            '<li><a class="button button-action button--primary button--small flow pull" href="?type=pull">'.$this->t('Pull').'</a></li>'.
            '<li><a class="button button-action button--primary button--small flow push" href="?type=push">'.$this->t('Push').'</a></li>'.
            '</ul>',
        ];

        return $form;
    }

    /**
     * Check whether all of the sub-forms per entity type should be open by
     * default. This is important if a Flow has been created without setting all
     * options where the user now edits the Flow once to provide all options
     * provided by the form.
     *
     * @return bool
     */
    protected function shouldOpenAll(FormStateInterface $form_state)
    {
        return isset($_GET['open']) || !empty($form_state->getValue('open_all'));
    }

    /**
     * Render all bundles for the given entity type. Displayed in vertical tabs
     * from the parent form.
     *
     * @param $type_key
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    protected function renderEntityType(array &$entity_type_list, FormStateInterface $form_state, $type_key)
    {
        $entity_types = $this->bundleInfoService->getAllBundleInfo();

        $entity_type = $entity_types[$type_key];

        ksort($entity_type);

        $current_values = $this->getCurrentValues($form_state);

        $bundles_rendered = [];

        $open_all = $this->shouldOpenAll($form_state);

        foreach ($entity_type as $entity_bundle_name => $entity_bundle) {
            $info = EntityHandlerPluginManager::getEntityTypeInfo($type_key, $entity_bundle_name);
            if (!empty($info['no_entity_type_handler']) || !empty($info['required_field_not_supported'])) {
                continue;
            }

            $bundle_id = $type_key.'-'.$entity_bundle_name;

            $edit = $form_state->getValue([$type_key, $entity_bundle_name, 'edit']);
            if ('fields' === $this->triggeredAction && $this->triggeringType === $type_key && $this->triggeringBundle === $entity_bundle_name) {
                $edit = '1';
            }

            if ($open_all && isset($current_values['sync_entities'][$bundle_id]['handler']) && 'ignore' !== $current_values['sync_entities'][$bundle_id]['handler']) {
                $edit = '1';
            }

            // If the version changed, we need to expand this so the new field is added.
            if (isset($current_values['sync_entities'][$bundle_id]['version']) && 'ignore' !== $current_values['sync_entities'][$bundle_id]['handler']) {
                $version = Flow::getEntityTypeVersion($type_key, $entity_bundle_name);
                if ($current_values['sync_entities'][$bundle_id]['version'] !== $version) {
                    $edit = '1';
                }
            }

            if ('1' == $edit) {
                $bundle_info = $this->renderEnabledBundle($form_state, $type_key, $entity_bundle_name);
            } elseif (!isset($current_values['sync_entities'][$bundle_id]['handler']) || 'ignore' === $current_values['sync_entities'][$bundle_id]['handler']) {
                $bundle_info = $this->renderDisabledBundle($form_state, $type_key, $entity_bundle_name);
            } else {
                $bundle_info = $this->renderBundleSummary($form_state, $type_key, $entity_bundle_name);
            }

            $bundles_rendered[$entity_bundle_name] = $bundle_info;
        }

        if (empty($bundles_rendered)) {
            return;
        }

        $bundles_rendered = array_merge($bundles_rendered, [
            '#type' => 'details',
            '#title' => str_replace('_', ' ', ucfirst($type_key)),
            '#group' => 'entity_type_list',
        ]);

        // Add information text for paragraphs that a specific commit is required.
        if ('paragraph' == $type_key) {
            $bundles_rendered['#description'] = 'If you want to select the pool per paragraph (Push to Pools set to "Allow"), Paragraphs version >= <strong>8.x-1.3</strong> is required.<br><br>';
        }

        $entity_type_list[$type_key] = $bundles_rendered;
    }

    /**
     * Get the current values for the config entity. Data is collected in the
     * following order:
     * - Data from existing config entity
     * - Extended and overwritten by user submitted data (if POST'd already)
     * - Extended by default values for implicit values (e.g. field config not
     * shown, so implicitly fields are included).
     */
    protected function getCurrentValues(FormStateInterface $form_state)
    {
        /**
         * @var \Drupal\cms_content_sync\Entity\Flow $flow
         */
        $flow = $this->entity;

        $result = [
            'name' => $flow->name,
            'id' => $flow->id,
            'status' => $flow->status(),
            'sync_entities' => $flow->sync_entities,
        ];

        $submitted = $form_state->cleanValues()->getValues();

        $entity_types = $this->bundleInfoService->getAllBundleInfo();

        if (!empty($submitted)) {
            if (isset($submitted['name'])) {
                $result['name'] = $submitted['name'];
            }
            if (isset($submitted['id'])) {
                $result['id'] = $submitted['id'];
            }
            if (isset($submitted['status'])) {
                $result['status'] = $submitted['status'];
            }

            foreach ($entity_types as $type_key => $bundles) {
                foreach ($bundles as $entity_bundle_name => $bundle) {
                    $bundle_id = $type_key.'-'.$entity_bundle_name;

                    // If this is not given that means that the user didn't change anything here (didn't click "edit"). So then
                    // we simply keep the existing values, i.e. nothing to do here.
                    if (!isset($submitted[$type_key][$entity_bundle_name]['handler'])) {
                        continue;
                    }

                    $bundle_settings = $submitted[$type_key][$entity_bundle_name];

                    // We handle field config outside of this array (same level as bundle config).
                    $result['sync_entities'][$bundle_id]['handler'] = $bundle_settings['handler'];
                    if (isset($bundle_settings['handler_settings'])) {
                        $result['sync_entities'][$bundle_id]['handler_settings'] = $bundle_settings['handler_settings'];
                    } else {
                        $result['sync_entities'][$bundle_id]['handler_settings'] = [];
                    }
                    if (!empty($bundle_settings['export'])) {
                        $result['sync_entities'][$bundle_id]['export'] = $bundle_settings['export'][$bundle_id]['export'];
                        $result['sync_entities'][$bundle_id]['export_pools'] = $bundle_settings['export'][$bundle_id]['export_pools'];
                        $result['sync_entities'][$bundle_id]['preview'] = $bundle_settings['export'][$bundle_id]['preview'];
                        $result['sync_entities'][$bundle_id]['pool_export_widget_type'] = $bundle_settings['export'][$bundle_id]['pool_export_widget_type'];
                        if (isset($bundle_settings['export'][$bundle_id]['export_deletion_settings']['export_deletion'])) {
                            $result['sync_entities'][$bundle_id]['export_deletion_settings']['export_deletion'] = $bundle_settings['export'][$bundle_id]['export_deletion_settings']['export_deletion'];
                        } else {
                            $result['sync_entities'][$bundle_id]['export_deletion_settings']['export_deletion'] = 0;
                        }
                    } else {
                        $result['sync_entities'][$bundle_id]['export'] = PushIntent::PUSH_DISABLED;
                    }

                    if (!empty($bundle_settings['import'])) {
                        $result['sync_entities'][$bundle_id]['import'] = $bundle_settings['import'][$bundle_id]['import'];
                        $result['sync_entities'][$bundle_id]['import_pools'] = $bundle_settings['import'][$bundle_id]['import_pools'];
                        if (isset($bundle_settings['import'][$bundle_id]['import_deletion_settings']['import_deletion'])) {
                            $result['sync_entities'][$bundle_id]['import_deletion_settings']['import_deletion'] = $bundle_settings['import'][$bundle_id]['import_deletion_settings']['import_deletion'];
                        } else {
                            $result['sync_entities'][$bundle_id]['import_deletion_settings']['import_deletion'] = 0;
                        }
                        if (isset($bundle_settings['import'][$bundle_id]['import_deletion_settings']['allow_local_deletion_of_import'])) {
                            $result['sync_entities'][$bundle_id]['import_deletion_settings']['allow_local_deletion_of_import'] = $bundle_settings['import'][$bundle_id]['import_deletion_settings']['allow_local_deletion_of_import'];
                        } else {
                            $result['sync_entities'][$bundle_id]['import_deletion_settings']['allow_local_deletion_of_import'] = 0;
                        }
                        $result['sync_entities'][$bundle_id]['import_updates'] = $bundle_settings['import'][$bundle_id]['import_updates'];
                    } else {
                        $result['sync_entities'][$bundle_id]['import'] = PullIntent::PULL_DISABLED;
                    }

                    // If the user set this to disabled, remove all associated configuration.
                    if ('ignore' === $bundle_settings['handler']) {
                        // Remove field configuration completely for this.
                        foreach ($result['sync_entities'] as $key => $value) {
                            if (substr($key, 0, strlen($bundle_id) + 1) === $bundle_id.'-') {
                                unset($result['sync_entities'][$key]);
                            }
                        }
                    } else {
                        if (EntityHandlerPluginManager::isEntityTypeFieldable($type_key)) {
                            /**
                             * @var \Drupal\Core\Field\FieldDefinitionInterface[] $fields
                             */
                            $fields = $this->entityFieldManager->getFieldDefinitions($type_key, $entity_bundle_name);

                            // @todo Test that the field config is in the right format (so as before).
                            foreach ($bundle_settings['fields'] as $field_name => $settings) {
                                if ('advanced' === $field_name) {
                                    continue;
                                }

                                if (empty($settings['handler'])) {
                                    $result['sync_entities'][$field_name] = [
                                        'handler' => 'ignore',
                                        'export' => PushIntent::PUSH_DISABLED,
                                        'import' => PullIntent::PULL_DISABLED,
                                    ];

                                    continue;
                                }

                                list(, , $key) = explode('-', $field_name);

                                if (isset($settings['handler_settings']['subscribe_only_to'])) {
                                    // @todo This should be handled by the Handler itself with another callback for saving / altering.
                                    if (!empty($settings['handler_settings']['subscribe_only_to'])) {
                                        /**
                                         * @var \Drupal\Core\Field\FieldDefinitionInterface[] $fields
                                         */
                                        $field_definition = $this->entityFieldManager->getFieldDefinitions($type_key, $entity_bundle_name)[$key];

                                        $type = $field_definition->getSetting('target_type');
                                        $storage = \Drupal::entityTypeManager()->getStorage($type);

                                        foreach ($settings['handler_settings']['subscribe_only_to'] as $i => $ref) {
                                            $entity = $storage->load($ref['target_id']);

                                            $settings['handler_settings']['subscribe_only_to'][$i] = [
                                                'type' => $entity->getEntityTypeId(),
                                                'bundle' => $entity->bundle(),
                                                'uuid' => $entity->uuid(),
                                            ];
                                        }
                                    }
                                }

                                $result['sync_entities'][$field_name] = $settings;
                            }

                            $handler = $this->entityPluginManager->createInstance($result['sync_entities'][$bundle_id]['handler'], [
                                'entity_type_name' => $type_key,
                                'bundle_name' => $entity_bundle_name,
                                'settings' => $result['sync_entities'][$bundle_id],
                                'sync' => null,
                            ]);

                            $forbidden_fields = $handler->getForbiddenFields();

                            $pools = Pool::getAll();
                            if (count($pools)) {
                                $reserved = reset($pools)
                                    ->getClient()
                                    ->getReservedPropertyNames();
                                $forbidden_fields = array_merge($forbidden_fields, $reserved);
                            }

                            foreach ($fields as $key => $field) {
                                $field_name = $bundle_id.'-'.$key;
                                if (isset($bundle_settings['fields'][$field_name])) {
                                    continue;
                                }

                                $field_handlers = $this->fieldPluginManager->getHandlerOptions($type_key, $entity_bundle_name, $key, $field, true);
                                if (empty($field_handlers) || in_array($key, $forbidden_fields)) {
                                    $handler_id = 'ignore';

                                    $result['sync_entities'][$field_name] = [
                                        'handler' => $handler_id,
                                        'handler_settings' => [],
                                        'export' => PushIntent::PUSH_DISABLED,
                                        'import' => PullIntent::PULL_DISABLED,
                                    ];
                                } else {
                                    reset($field_handlers);
                                    $handler_id = empty($field_default_values['handler']) ? key($field_handlers) : $field_default_values['handler'];

                                    $result['sync_entities'][$field_name] = [
                                        'handler' => $handler_id,
                                        'handler_settings' => [],
                                        'export' => PushIntent::PUSH_AUTOMATICALLY,
                                        'import' => PullIntent::PULL_AUTOMATICALLY,
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }

        if (!isset($result['sync_entities'])) {
            $result['sync_entities'] = [];
        }

        foreach ($result['sync_entities'] as $key => &$config) {
            if ('#' == $key[0]) {
                continue;
            }

            $count = substr_count($key, '-');
            if (0 == $count) {
                continue;
            }

            if (1 == $count) {
                list($entity_type, $bundle) = explode('-', $key);

                if (empty($entity_types[$entity_type][$bundle])) {
                    unset($result['sync_entities'][$key]);

                    continue;
                }
            } else {
                list($entity_type, $bundle, $field) = explode('-', $key);

                if (empty($entity_types[$entity_type][$bundle])) {
                    unset($result['sync_entities'][$key]);

                    continue;
                }

                /**
                 * @var \Drupal\Core\Field\FieldDefinitionInterface[] $fields
                 */
                $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);

                // Handle removed fields gracefully.
                if (empty($fields[$field])) {
                    unset($result['sync_entities'][$key]);

                    continue;
                }
            }
        }

        return $result;
    }

    /**
     * Render the bundle edit form.
     *
     * @param $type_key
     * @param $entity_bundle_name
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     *
     * @return array
     */
    protected function renderEnabledBundle(FormStateInterface $form_state, $type_key, $entity_bundle_name)
    {
        $entity_types = $this->bundleInfoService->getAllBundleInfo();

        $entity_bundle = $entity_types[$type_key][$entity_bundle_name];

        $bundle_id = $type_key.'-'.$entity_bundle_name;

        $settings_id = 'sync-entities-'.$bundle_id;

        $type = $this->getCurrentFormType();
        $allow_push = Flow::TYPE_PUSH === $type || Flow::TYPE_BOTH === $type;
        $allow_pull = Flow::TYPE_PULL === $type || Flow::TYPE_BOTH === $type;

        $current_values = $this->getCurrentValues($form_state);

        // Before a flow can be created, at least one pool must exist.
        // Get all pool entities.
        $pool_entities = Pool::getAll();

        $push_option_labels = [
            PushIntent::PUSH_DISABLED => $this->t('Disabled')->render(),
            PushIntent::PUSH_AUTOMATICALLY => $this->t('All')->render(),
            PushIntent::PUSH_AS_DEPENDENCY => $this->t('Referenced')->render(),
            PushIntent::PUSH_MANUALLY => $this->t('Manually')->render(),
        ];

        $pull_option_labels = [
            PullIntent::PULL_DISABLED => $this->t('Disabled')->render(),
            PullIntent::PULL_AUTOMATICALLY => $this->t('All')->render(),
            PullIntent::PULL_AS_DEPENDENCY => $this->t('Referenced')->render(),
            PullIntent::PULL_MANUALLY => $this->t('Manually')->render(),
        ];

        $def_sync_entities = $this->getCurrentValues($form_state)['sync_entities'];

        $display_modes = $this->entityTypeManager
            ->getStorage('entity_view_display')
            ->loadMultiple();

        $display_modes_ids = array_keys($display_modes);

        $version = Flow::getEntityTypeVersion($type_key, $entity_bundle_name);

        $entity_push_bundle_row = [];

        $available_preview_modes = [];
        foreach ($display_modes_ids as $id) {
            $length = strlen($type_key) + strlen($entity_bundle_name) + 2;
            if (substr($id, 0, $length) != $type_key.'.'.$entity_bundle_name.'.') {
                continue;
            }
            $id = substr($id, $length);
            $label = $id;
            $available_preview_modes[$id] = $label;
        }

        if (!isset($def_sync_entities[$type_key.'-'.$entity_bundle_name])) {
            $row_default_values = [
                'id' => $type_key.'-'.$entity_bundle_name,
                'export' => $allow_push ? ('node' === $type_key ? PushIntent::PUSH_AUTOMATICALLY : PushIntent::PUSH_AS_DEPENDENCY) : null,
                'export_deletion_settings' => [
                    'export_deletion' => true,
                ],
                'import' => $allow_pull ? ('node' === $type_key ? PullIntent::PULL_AUTOMATICALLY : PullIntent::PULL_AS_DEPENDENCY) : null,
                'import_deletion_settings' => [
                    'import_deletion' => true,
                    'allow_local_deletion_of_import' => false,
                ],
                'handler_settings' => [],
                'import_updates' => PullIntent::PULL_UPDATE_FORCE,
                'preview' => Flow::PREVIEW_DISABLED,
                'display_name' => $this->t('@bundle', [
                    '@bundle' => $entity_bundle['label'],
                ])->render(),
                'entity_type' => $type_key,
                'entity_bundle' => $entity_bundle_name,
                'pool_export_widget_type' => 'checkboxes',
            ];
            foreach ($pool_entities as $pool) {
                $row_default_values['export_pools'][$pool->id()] = PushIntent::PUSH_AUTOMATICALLY === $row_default_values['export'] ? Pool::POOL_USAGE_FORCE : Pool::POOL_USAGE_ALLOW;
                $row_default_values['import_pools'][$pool->id()] = Pool::POOL_USAGE_FORCE;
            }
        } else {
            $row_default_values = $def_sync_entities[$type_key.'-'.$entity_bundle_name];

            if (empty($row_default_values['export']) || PushIntent::PUSH_DISABLED === $row_default_values['export']) {
                if (!$allow_pull) {
                    $row_default_values['export'] = 'node' === $type_key ? PushIntent::PUSH_AUTOMATICALLY : PushIntent::PUSH_AS_DEPENDENCY;
                }
            }

            if (empty($row_default_values['import']) || PullIntent::PULL_DISABLED === $row_default_values['import']) {
                if (!$allow_push) {
                    $row_default_values['import'] = 'node' === $type_key ? PullIntent::PULL_AUTOMATICALLY : PullIntent::PULL_AS_DEPENDENCY;
                }
            }
        }

        $entity_handlers = $this->entityPluginManager->getHandlerOptions($type_key, $entity_bundle_name, true);
        $entity_handler_names = array_keys($entity_handlers);
        $handler_id = empty($row_default_values['handler']) || 'ignore' === $row_default_values['handler'] ?
      reset($entity_handler_names) :
      $row_default_values['handler'];

        $bundle_info = [
            '#type' => 'details',
            '#prefix' => '<div id="'.$settings_id.'">',
            '#suffix' => '</div>',
            '#open' => true,
            '#title' => $this->t('@bundle (@machine_name)', [
                '@bundle' => $entity_bundle['label'],
                '@machine_name' => $entity_bundle_name,
            ]),
            'version' => [
                '#markup' => '<small>Entity type - bundle version: '.$version.'</small>'.
                (empty($row_default_values['version']) || $version == $row_default_values['version'] ? '' : '<br><strong>Changed from '.$row_default_values['version'].'</strong>'),
            ],
        ];

        $bundle_info['edit'] = [
            '#type' => 'hidden',
            '#value' => '1',
        ];

        $bundle_info['handler'] = [
            '#type' => 'select',
            '#title' => $this->t('Handler'),
            '#title_display' => 'invisible',
            '#options' => $entity_handlers,
            '#default_value' => $handler_id,
        ];

        /**
         * @var \Drupal\cms_content_sync\Plugin\EntityHandlerInterface $handler
         */
        $handler = $this->entityPluginManager->createInstance($handler_id, [
            'entity_type_name' => $type_key,
            'bundle_name' => $entity_bundle_name,
            'settings' => $row_default_values,
            'sync' => null,
        ]);

        $allowed_push_options = $handler->getAllowedPushOptions();
        $push_options = [];
        foreach ($allowed_push_options as $option) {
            $push_options[$option] = $push_option_labels[$option];
        }

        if (!$allow_pull) {
            unset($push_options[PushIntent::PUSH_DISABLED]);
        }

        $bundle_info['disable'] = [
            '#type' => 'submit',
            '#value' => $this->t('Disable'),
            '#name' => 'disable-'.$type_key.'-'.$entity_bundle_name,
            '#submit' => ['::disableBundle'],
            '#entity_type' => $type_key,
            '#bundle' => $entity_bundle_name,
            '#limit_validation_errors' => [],
            '#attributes' => [
                'class' => ['button--danger'],
            ],
            '#ajax' => [
                'callback' => '::ajaxReturn',
                'wrapper' => $settings_id,
                'method' => 'replace',
                'effect' => 'fade',
                'progress' => [
                    'type' => 'throbber',
                    'message' => 'loading settings...',
                ],
            ],
        ];

        if ('1' === $form_state->getValue([
            $type_key,
            $entity_bundle_name,
            'show-version-mismatches',
        ])) {
            $bundle_info['version_mismatch_'.$type_key.'_'.$entity_bundle_name] = _cms_content_sync_display_version_mismatches(null, $form_state);
        } else {
            $bundle_info['version_mismatch_'.$type_key.'_'.$entity_bundle_name] = [
                '#type' => 'submit',
                '#value' => t('Show version mismatches'),
                '#name' => 'version-'.$type_key.'-'.$entity_bundle_name,
                '#submit' => ['::showVersionMismatches'],
                '#entity_type' => $type_key,
                '#bundle' => $entity_bundle_name,
                '#recursive' => false,
                '#limit_validation_errors' => [],
                '#ajax' => [
                    'callback' => '::ajaxReturn',
                    'wrapper' => $settings_id,
                    'method' => 'replace',
                    'effect' => 'fade',
                    'progress' => [
                        'type' => 'throbber',
                        'message' => 'loading settings...',
                    ],
                ],
            ];
        }

        if ('ignore' != $handler_id) {
            $advanced_settings = $handler->getHandlerSettings(isset($current_values['sync_entities'][$bundle_id]['handler_settings']) ? $current_values['sync_entities'][$bundle_id]['handler_settings'] : [], $this->getCurrentFormType());
            if (count($advanced_settings)) {
                $bundle_info['handler_settings'] = array_merge([
                    '#type' => 'container',
                ], $advanced_settings);
            }
        }

        if (!isset($bundle_info['handler_settings'])) {
            $bundle_info['handler_settings'] = [
                '#markup' => '<br><br>',
            ];
        }

        if ($allow_push) {
            $entity_push_bundle_row['export'] = [
                '#type' => 'select',
                '#title' => $this->t('Push'),
                '#title_display' => 'invisible',
                '#options' => $push_options,
                '#default_value' => $row_default_values['export'],
            ];

            foreach ($pool_entities as $pool) {
                $entity_push_bundle_row['export_pools'][$pool->id()] = [
                    '#type' => 'select',
                    '#title' => $this->t($pool->label()),
                    '#options' => [
                        Pool::POOL_USAGE_FORCE => $this->t('Force'),
                        Pool::POOL_USAGE_ALLOW => $this->t('Allow'),
                        Pool::POOL_USAGE_FORBID => $this->t('Forbid'),
                    ],
                    '#default_value' => isset($row_default_values['export_pools'][$pool->id()])
                    ? $row_default_values['export_pools'][$pool->id()]
                    : (
                        $this->entity->id()
                        ? Pool::POOL_USAGE_FORBID
                        : (
                            PushIntent::PUSH_AUTOMATICALLY === $row_default_values['export']
                        ? Pool::POOL_USAGE_FORCE
                        : Pool::POOL_USAGE_ALLOW
                        )
                    ),
                ];
            }

            $entity_push_bundle_row['export_deletion_settings']['export_deletion'] = [
                '#type' => 'checkbox',
                '#title' => $this->t('Push deletion'),
                '#default_value' => isset($row_default_values['export_deletion_settings']['export_deletion']) && 1 == $row_default_values['export_deletion_settings']['export_deletion'],
            ];

            $entity_push_bundle_row['pool_export_widget_type'] = [
                '#type' => 'select',
                '#options' => [
                    'checkboxes' => $this->t('Checkboxes'),
                    'radios' => $this->t('Radio boxes'),
                    'single_select' => $this->t('Single select'),
                    'multi_select' => $this->t('Multi select'),
                ],
                '#default_value' => isset($row_default_values['pool_export_widget_type']) ? $row_default_values['pool_export_widget_type'] : 'checkboxes',
            ];

            $options = array_merge([
                Flow::PREVIEW_DISABLED => $this->t('Disabled')->render(),
            ], 'ignore' == $handler_id ? [] : array_merge([
                Flow::PREVIEW_TABLE => $this->t('Default')
                    ->render(),
            ], $available_preview_modes));
            $default = 'ignore' == $handler_id ? Flow::PREVIEW_DISABLED : Flow::PREVIEW_TABLE;
            $entity_push_bundle_row['preview'] = [
                '#type' => 'select',
                '#title' => $this->t('Preview'),
                '#title_display' => 'invisible',
                '#options' => $options,
                '#default_value' => isset($row_default_values['preview']) || 'ignore' == $handler_id ? $row_default_values['preview'] : $default,
                '#description' => $this->t('Make sure to go to the general "Settings" and enable previews to make use of this.'),
            ];

            $entity_push_table = [
                '#type' => 'table',
                '#sticky' => true,
                '#header' => [
                    $this->t('Push'),
                    $this->t('Push to pools'),
                    $this->t('Push deletions'),
                    $this->t('Pool widget'),
                    $this->t('Preview'),
                ],
                $type_key.'-'.$entity_bundle_name => $entity_push_bundle_row,
            ];
        }

        if ($allow_pull) {
            $allowed_pull_options = $handler->getAllowedPullOptions();
            $pull_options = [];
            foreach ($allowed_pull_options as $option) {
                $pull_options[$option] = $pull_option_labels[$option];
            }

            if (!$allow_push) {
                unset($pull_options[PullIntent::PULL_DISABLED]);
            }

            $entity_pull_bundle_row['import'] = [
                '#type' => 'select',
                '#title' => $this->t('Pull'),
                '#title_display' => 'invisible',
                '#options' => $pull_options,
                '#default_value' => $row_default_values['import'],
            ];

            foreach ($pool_entities as $pool) {
                $entity_pull_bundle_row['import_pools'][$pool->id()] = [
                    '#type' => 'select',
                    '#title' => $this->t($pool->label()),
                    '#options' => [
                        Pool::POOL_USAGE_FORCE => $this->t('Force'),
                        Pool::POOL_USAGE_ALLOW => $this->t('Allow'),
                        Pool::POOL_USAGE_FORBID => $this->t('Forbid'),
                    ],
                    '#default_value' => isset($row_default_values['import_pools'][$pool->id()])
                    ? $row_default_values['import_pools'][$pool->id()]
                    : (
                        $this->entity->id()
                        ? Pool::POOL_USAGE_FORBID
                        : Pool::POOL_USAGE_FORCE
                    ),
                ];
            }

            $entity_pull_bundle_row['import_deletion_settings']['import_deletion'] = [
                '#type' => 'checkbox',
                '#title' => $this->t('Pull deletion'),
                '#default_value' => isset($row_default_values['import_deletion_settings']['import_deletion']) && 1 == $row_default_values['import_deletion_settings']['import_deletion'],
            ];

            $entity_pull_bundle_row['import_deletion_settings']['allow_local_deletion_of_import'] = [
                '#type' => 'checkbox',
                '#title' => $this->t('Allow deletion of pulled content'),
                '#default_value' => isset($row_default_values['import_deletion_settings']['allow_local_deletion_of_import']) && 1 == $row_default_values['import_deletion_settings']['allow_local_deletion_of_import'],
            ];

            $entity_pull_bundle_row['import_updates'] = [
                '#type' => 'select',
                '#options' => [
                    PullIntent::PULL_UPDATE_FORCE => $this->t('Dismiss local changes'),
                    PullIntent::PULL_UPDATE_IGNORE => $this->t('Ignore updates completely'),
                    PullIntent::PULL_UPDATE_FORCE_AND_FORBID_EDITING => $this->t('Forbid local changes and update'),
                    PullIntent::PULL_UPDATE_FORCE_UNLESS_OVERRIDDEN => $this->t('Update unless overwritten locally'),
                ],
                '#default_value' => isset($row_default_values['import_updates']) ? $row_default_values['import_updates'] : null,
            ];

            if ('node' === $type_key) {
                $bundle_type = $this->entityTypeManager->getStorage('node_type')
                    ->load($entity_bundle_name);
                if ($bundle_type instanceof RevisionableEntityBundleInterface && $bundle_type->shouldCreateNewRevision()) {
                    $entity_pull_bundle_row['import_updates']['#options'][PullIntent::PULL_UPDATE_UNPUBLISHED] = $this->t('Create unpublished revisions');
                }
            }

            $entity_pull_table = [
                '#type' => 'table',
                '#sticky' => true,
                '#header' => [
                    $this->t('Pull'),
                    $this->t('Pull from pool'),
                    $this->t('Pull deletions'),
                    $this->t('Pull updates'),
                ],
                $type_key.'-'.$entity_bundle_name => $entity_pull_bundle_row,
            ];
        }

        $show_all_fields = $form_state->getValue([
            $type_key,
            $entity_bundle_name,
            'fields',
            'advanced',
            'show-all',
        ]);

        $entity_field_table = $this->renderFields($form_state, $type_key, $entity_bundle_name, '1' === $show_all_fields);

        if (isset($entity_push_table)) {
            $bundle_info['export'] = $entity_push_table;
        }

        if (isset($entity_pull_table)) {
            $bundle_info['import'] = $entity_pull_table;
        }

        if (isset($entity_field_table)) {
            $bundle_info['fields'] = $entity_field_table;
        }

        return $bundle_info;
    }

    /**
     * Render the fields of the given entity type; either all of them or only
     * those that either have:
     * - advanced handler settings available
     * - a handler that was intentionally disabled.
     *
     * @param $type_key
     * @param $entity_bundle_name
     * @param bool $expanded
     *
     * @throws \Drupal\Component\Plugin\Exception\PluginException
     *
     * @return array
     */
    protected function renderFields(FormStateInterface $form_state, $type_key, $entity_bundle_name, $expanded = false)
    {
        $bundle_id = $type_key.'-'.$entity_bundle_name;

        $settings_id = 'sync-entities-'.$bundle_id;

        $field_settings_id = $settings_id.'-fields';

        $form_type = $this->getCurrentFormType();
        $allow_push = Flow::TYPE_PUSH === $form_type || Flow::TYPE_BOTH === $form_type;
        $allow_pull = Flow::TYPE_PULL === $form_type || Flow::TYPE_BOTH === $form_type;

        $current_values = $this->getCurrentValues($form_state);

        $push_option_labels_fields = [
            PushIntent::PUSH_DISABLED => $this->t('No')->render(),
            PushIntent::PUSH_AUTOMATICALLY => $this->t('Yes')->render(),
        ];

        $pull_option_labels_fields = [
            PullIntent::PULL_DISABLED => $this->t('No')->render(),
            PullIntent::PULL_AUTOMATICALLY => $this->t('Yes')->render(),
        ];

        $field_map = $this->entityFieldManager->getFieldMap();

        $def_sync_entities = $this->getCurrentValues($form_state)['sync_entities'];

        $entity_handlers = $this->entityPluginManager->getHandlerOptions($type_key, $entity_bundle_name, true);
        $entity_handler_names = array_keys($entity_handlers);
        $handler_id = empty($current_values['sync_entities'][$bundle_id]['handler']) || 'ignore' === $current_values['sync_entities'][$bundle_id]['handler'] ?
      reset($entity_handler_names) :
      $current_values['sync_entities'][$bundle_id]['handler'];

        $handler = $this->entityPluginManager->createInstance($handler_id, [
            'entity_type_name' => $type_key,
            'bundle_name' => $entity_bundle_name,
            'settings' => empty($current_values['sync_entities'][$bundle_id]) ? [] : $current_values['sync_entities'][$bundle_id],
            'sync' => null,
        ]);

        if (isset($field_map[$type_key])) {
            $entity_field_table = [
                '#type' => 'table',
                '#prefix' => '<div id="'.$field_settings_id.'"><h3>'.$this->t('Fields').'</h3>',
                '#suffix' => '</div>',
                '#header' => array_merge([
                    $this->t('Name'),
                    $this->t('Handler'),
                    $this->t('Handler settings'),
                ], $allow_push && Flow::TYPE_BOTH === !$form_type ? [
                    $this->t('Push'),
                ] : [], $allow_pull && Flow::TYPE_BOTH === !$form_type ? [
                    $this->t('Pull'),
                ] : []),
            ];

            $forbidden_fields = $handler->getForbiddenFields();
            $pools = Pool::getAll();
            if (count($pools)) {
                $reserved = reset($pools)
                    ->getClient()
                    ->getReservedPropertyNames();
                $forbidden_fields = array_merge($forbidden_fields, $reserved);
            }

            $entityFieldManager = $this->entityFieldManager;
            /**
             * @var \Drupal\Core\Field\FieldDefinitionInterface[] $fields
             */
            $fields = $entityFieldManager->getFieldDefinitions($type_key, $entity_bundle_name);
            foreach ($fields as $key => $field) {
                $field_id = $type_key.'-'.$entity_bundle_name.'-'.$key;

                $field_row = [];

                $title = $key;
                $referenced_type = null;
                $referenced_bundles = 'all';
                $bundles = '';
                $show_enable_all = false;
                if (in_array($field->getType(), [
                    'entity_reference',
                    'entity_reference_revisions',
                    'webform',
                ])) {
                    $referenced_type = $field->getSetting('target_type');
                } elseif (in_array($field->getType(), ['image', 'file', 'file_uri'])) {
                    $referenced_type = 'file';
                } elseif (in_array($field->getType(), ['bricks'])) {
                    $referenced_type = 'brick';
                } elseif (in_array($field->getType(), ['field_collection'])) {
                    $referenced_type = 'field_collection_item';
                }

                $field_settings = $field->getSettings();
                if ((!empty($field_settings['handler_settings']) || 'brick' === $referenced_type) && !empty($field_settings['handler_settings']['target_bundles'])) {
                    $bundles .= '<ul>';

                    $referenced_bundles = [];

                    foreach ($field_settings['handler_settings']['target_bundles'] as $bundle) {
                        $bundles .= '<li>'.$bundle.'</li>';

                        $referenced_bundles[] = $bundle;

                        if (empty($current_values['sync_entities'][$referenced_type.'-'.$bundle]['handler']) || Flow::HANDLER_IGNORE == $current_values['sync_entities'][$referenced_type.'-'.$bundle]['handler']) {
                            if ('1' != $form_state->getValue([
                                $referenced_type,
                                $bundle,
                                'edit',
                            ])) {
                                $entity_handlers = $this->entityPluginManager->getHandlerOptions($referenced_type, $bundle, true);
                                if (!empty($entity_handlers)) {
                                    $show_enable_all = true;
                                }
                            }
                        }
                    }
                    $bundles .= '</ul>';
                }

                if ($referenced_type) {
                    $title .= '<br><small>Reference to '.$referenced_type;
                    $title .= !empty($bundles) ? ':'.$bundles : '';
                    $title .= '</small>';

                    if (empty($bundles)) {
                        $entity_types = $this->bundleInfoService->getAllBundleInfo();

                        foreach ($entity_types[$referenced_type] as $bundle => $set) {
                            if (empty($current_values['sync_entities'][$referenced_type.'-'.$bundle]['handler']) || Flow::HANDLER_IGNORE == $current_values['sync_entities'][$referenced_type.'-'.$bundle]['handler']) {
                                if ('1' != $form_state->getValue([
                                    $referenced_type,
                                    $bundle,
                                    'edit',
                                ])) {
                                    $entity_handlers = $this->entityPluginManager->getHandlerOptions($referenced_type, $bundle, true);
                                    if (!empty($entity_handlers)) {
                                        $show_enable_all = true;
                                    }
                                }

                                break;
                            }
                        }
                    }
                }

                $field_row['bundle'] = [
                    '#markup' => $title,
                ];

                if ($show_enable_all) {
                    $field_row['bundle']['enable-all'] = [
                        '#type' => 'submit',
                        '#value' => $this->t('Enable all'),
                        '#name' => 'enable_all-'.$type_key.'-'.$entity_bundle_name.'-'.$key,
                        '#submit' => ['::enableAllReferenced'],
                        '#entity_type' => $type_key,
                        '#bundle' => $entity_bundle_name,
                        '#field' => $key,
                        '#referenced_type' => $referenced_type,
                        '#referenced_bundles' => $referenced_bundles,
                        '#limit_validation_errors' => [],
                        '#ajax' => [
                            'callback' => '::enableAllReferencedReturn',
                            'progress' => [
                                'type' => 'throbber',
                                'message' => 'loading settings...',
                            ],
                        ],
                    ];
                }

                if (!isset($def_sync_entities[$field_id])) {
                    $field_default_values = [
                        'id' => $field_id,
                        'export' => null,
                        'import' => null,
                        'preview' => null,
                        'entity_type' => $type_key,
                        'entity_bundle' => $entity_bundle_name,
                    ];
                } else {
                    $field_default_values = $def_sync_entities[$field_id];
                }
                if (!empty($input[$field_id])) {
                    $field_default_values = array_merge($field_default_values, $input[$field_id]);
                }

                if (false !== in_array($key, $forbidden_fields)) {
                    $handler_id = 'ignore';
                    $field_handlers = [
                        'ignore' => $this->t('Default')->render(),
                    ];
                } else {
                    $field_handlers = $this->fieldPluginManager->getHandlerOptions($type_key, $entity_bundle_name, $key, $field, true);
                    if (empty($field_handlers)) {
                        $handler_id = 'ignore';
                    } else {
                        reset($field_handlers);
                        $handler_id = empty($field_default_values['handler']) ? key($field_handlers) : $field_default_values['handler'];
                    }
                }

                $options = count($field_handlers) ? ($field->isRequired() ? $field_handlers : array_merge([
                    'ignore' => $this->t('Ignore')
                        ->render(),
                ], $field_handlers)) : [
                    'ignore' => $this->t('Not supported')->render(),
                ];

                $field_row['handler'] = [
                    '#type' => 'select',
                    '#title' => $this->t('Handler'),
                    '#title_display' => 'invisible',
                    '#options' => $options,
                    '#disabled' => !count($field_handlers) || (1 == count($field_handlers) && isset($field_handlers['ignore'])),
                    '#default_value' => $handler_id,
                    '#limit_validation_errors' => [],
                ];

                if ('ignore' == $handler_id) {
                    // Disabled means we don't syndicate it as a normal field handler. Instead, the entity handler will already take care of it as it's a required property.
                    // But saying "no" will be confusing to users as it's actually syndicated. So we use DISABLED => YES for the naming.
                    $push_options = [
                        PushIntent::PUSH_DISABLED => $this->t('Yes')->render(),
                    ];
                } else {
                    /**
                     * @var \Drupal\cms_content_sync\Plugin\FieldHandlerInterface $handler
                     */
                    $handler = $this->fieldPluginManager->createInstance($handler_id, [
                        'entity_type_name' => $type_key,
                        'bundle_name' => $entity_bundle_name,
                        'field_name' => $key,
                        'field_definition' => $field,
                        'settings' => $field_default_values,
                        'sync' => $this->entity,
                    ]);

                    $allowed_push_options = $handler->getAllowedPushOptions();
                    $push_options = [];
                    foreach ($allowed_push_options as $option) {
                        $push_options[$option] = $push_option_labels_fields[$option];
                    }
                }

                $field_row['handler_settings'] = ['#markup' => ''];

                if ('ignore' != $handler_id) {
                    $advanced_settings = $handler->getHandlerSettings(isset($current_values['sync_entities'][$field_id]['handler_settings']) ? $current_values['sync_entities'][$field_id]['handler_settings'] : [], $form_type);
                    if (count($advanced_settings)) {
                        $field_row['handler_settings'] = array_merge([
                            '#type' => 'container',
                        ], $advanced_settings);
                    } elseif (!$expanded && !$referenced_type) {
                        continue;
                    }
                } elseif (!$expanded && 1 === count($options) && !$referenced_type) {
                    continue;
                }

                if ($allow_push) {
                    $field_row['export'] = [
                        '#type' => 'select',
                        '#title' => $this->t('Push'),
                        '#title_display' => 'invisible',
                        '#disabled' => count($push_options) < 2,
                        '#options' => $push_options,
                        '#default_value' => $field_default_values['export'] ? $field_default_values['export'] : (isset($push_options[PushIntent::PUSH_AUTOMATICALLY]) ? PushIntent::PUSH_AUTOMATICALLY : null),
                        '#access' => Flow::TYPE_BOTH === $form_type ? true : false,
                    ];
                }

                if ('ignore' == $handler_id) {
                    // Disabled means we don't syndicate it as a normal field handler. Instead, the entity handler will already take care of it as it's a required property.
                    // But saying "no" will be confusing to users as it's actually syndicated. So we use DISABLED => YES for the naming.
                    $pull_options = [
                        PullIntent::PULL_DISABLED => $this->t('Yes')->render(),
                    ];
                } else {
                    $allowed_pull_options = $handler->getAllowedPullOptions();
                    $pull_options = [];
                    foreach ($allowed_pull_options as $option) {
                        $pull_options[$option] = $pull_option_labels_fields[$option];
                    }
                }

                if ($allow_pull) {
                    $field_row['import'] = [
                        '#type' => 'select',
                        '#title' => $this->t('Pull'),
                        '#title_display' => 'invisible',
                        '#options' => $pull_options,
                        '#disabled' => count($pull_options) < 2,
                        '#default_value' => !empty($field_default_values['import']) ? $field_default_values['import'] : (isset($pull_options[PullIntent::PULL_AUTOMATICALLY]) ? PullIntent::PULL_AUTOMATICALLY : null),
                        '#access' => Flow::TYPE_BOTH === $form_type ? true : false,
                    ];
                }

                $entity_field_table[$field_id] = $field_row;
            }

            if (!$expanded) {
                $entity_field_table['advanced']['show-all-action'] = [
                    '#type' => 'submit',
                    '#value' => $this->t('Show all fields'),
                    '#name' => 'show_all-'.$type_key.'-'.$entity_bundle_name,
                    '#submit' => ['::showAllFields'],
                    '#entity_type' => $type_key,
                    '#bundle' => $entity_bundle_name,
                    '#limit_validation_errors' => [],
                    '#ajax' => [
                        'callback' => '::ajaxReturn',
                        'wrapper' => $field_settings_id,
                        'method' => 'replace',
                        'effect' => 'fade',
                        'progress' => [
                            'type' => 'throbber',
                            'message' => 'loading settings...',
                        ],
                    ],
                ];
            }

            $entity_field_table['advanced']['show-all'] = [
                '#type' => 'hidden',
                '#value' => $expanded ? '1' : '0',
            ];

            return $entity_field_table;
        }
    }

    /**
     * The bundle isn't pushed or pulled right now. The user can enable it with a
     * button then.
     *
     * @param $type_key
     * @param $entity_bundle_name
     *
     * @return array
     */
    protected function renderDisabledBundle(FormStateInterface $form_state, $type_key, $entity_bundle_name)
    {
        $entity_types = $this->bundleInfoService->getAllBundleInfo();

        $entity_bundle = $entity_types[$type_key][$entity_bundle_name];

        $bundle_id = $type_key.'-'.$entity_bundle_name;

        $settings_id = 'sync-entities-'.$bundle_id;

        $bundle_info = [
            '#prefix' => '<div id="'.$settings_id.'">',
            '#suffix' => '</div>',
            '#markup' => '<h2>'.$this->t('@bundle (@machine_name)', [
                '@bundle' => $entity_bundle['label'],
                '@machine_name' => $entity_bundle_name,
            ]).'</h2>',
        ];

        $entity_handlers = $this->entityPluginManager->getHandlerOptions($type_key, $entity_bundle_name, true);
        if (empty($entity_handlers)) {
            $bundle_info['#markup'] .= '<p>This entity type / bundle is not supported.</p>';
        } else {
            $bundle_info['handler'] = [
                '#type' => 'hidden',
                '#value' => 'ignore',
            ];

            $bundle_info['edit'] = [
                '#type' => 'hidden',
                '#value' => '0',
            ];

            $title = $this->t('Enable');

            $bundle_info['enable'] = [
                '#type' => 'submit',
                '#value' => $title,
                '#name' => 'enable-'.$type_key.'-'.$entity_bundle_name,
                '#submit' => ['::enableBundle'],
                '#entity_type' => $type_key,
                '#bundle' => $entity_bundle_name,
                '#limit_validation_errors' => [],
                '#attributes' => [
                    'class' => ['button--primary'],
                ],
                '#ajax' => [
                    'callback' => '::ajaxReturn',
                    'wrapper' => $settings_id,
                    'method' => 'replace',
                    'effect' => 'fade',
                    'progress' => [
                        'type' => 'throbber',
                        'message' => 'loading settings...',
                    ],
                ],
            ];
        }

        return $bundle_info;
    }

    /**
     * Bundle has settings already, but the user is editing the Flow so by
     * default we don't show all bundle edit forms as open but hide them all to
     * save space and make the form faster. The user can then click Edit to
     * change settings.
     *
     * @param $type_key
     * @param $entity_bundle_name
     *
     * @return array
     */
    protected function renderBundleSummary(FormStateInterface $form_state, $type_key, $entity_bundle_name)
    {
        $entity_types = $this->bundleInfoService->getAllBundleInfo();

        $entity_bundle = $entity_types[$type_key][$entity_bundle_name];

        $bundle_id = $type_key.'-'.$entity_bundle_name;

        $settings_id = 'sync-entities-'.$bundle_id;

        $current_values = $this->getCurrentValues($form_state);

        $push_option_labels = [
            PushIntent::PUSH_DISABLED => $this->t('Disabled')->render(),
            PushIntent::PUSH_AUTOMATICALLY => $this->t('All')->render(),
            PushIntent::PUSH_AS_DEPENDENCY => $this->t('Referenced')->render(),
            PushIntent::PUSH_MANUALLY => $this->t('Manually')->render(),
        ];

        $pull_option_labels = [
            PullIntent::PULL_DISABLED => $this->t('Disabled')->render(),
            PullIntent::PULL_AUTOMATICALLY => $this->t('All')->render(),
            PullIntent::PULL_AS_DEPENDENCY => $this->t('Referenced')->render(),
            PullIntent::PULL_MANUALLY => $this->t('Manually')->render(),
        ];

        $bundle_info = [
            '#prefix' => '<div id="'.$settings_id.'">',
            '#suffix' => '</div>',
            '#markup' => '<h2>'.$this->t('@bundle (@machine_name)', [
                '@bundle' => $entity_bundle['label'],
                '@machine_name' => $entity_bundle_name,
            ]).'</h2>',
        ];

        $entity_handlers = $this->entityPluginManager->getHandlerOptions($type_key, $entity_bundle_name, true);
        if (empty($entity_handlers)) {
            $bundle_info['#markup'] .= '<p>This entity type / bundle is not supported.</p>';
        } else {
            $does_push = isset($current_values['sync_entities'][$bundle_id]['export']) && PushIntent::PUSH_DISABLED !== $current_values['sync_entities'][$bundle_id]['export'];
            $does_pull = isset($current_values['sync_entities'][$bundle_id]['import']) && PullIntent::PULL_DISABLED !== $current_values['sync_entities'][$bundle_id]['import'];
            $push_label = $does_push ? 'Push '.$push_option_labels[$current_values['sync_entities'][$bundle_id]['export']] : null;
            $pull_label = $does_pull ? 'Pull '.$pull_option_labels[$current_values['sync_entities'][$bundle_id]['import']] : null;
            $bundle_info['summary'] = [
                '#markup' => $push_label ? (
                    $pull_label ? $push_label.' and '.$pull_label : $push_label
                ) : (
                    $does_pull ? $pull_label : 'Not configured'
                ),
            ];

            $title = $this->t('Edit');

            $bundle_info['edit'] = [
                '#type' => 'hidden',
                '#value' => '0',
            ];

            $bundle_info['enable'] = [
                '#type' => 'submit',
                '#submit' => ['::enableBundle'],
                '#value' => $title,
                '#name' => 'enable-'.$type_key.'-'.$entity_bundle_name,
                '#entity_type' => $type_key,
                '#bundle' => $entity_bundle_name,
                '#limit_validation_errors' => [],
                '#ajax' => [
                    'callback' => '::ajaxReturn',
                    'wrapper' => $settings_id,
                    'method' => 'replace',
                    'effect' => 'fade',
                    'progress' => [
                        'type' => 'throbber',
                        'message' => 'loading settings...',
                    ],
                ],
            ];
        }

        return $bundle_info;
    }

    /**
     * AJAX requests will make the form forget the 'edit' status of the bundles,
     * thus their form elements will disappear in the render array (*not in the
     * UI though*), so even though the user still sees them correctly, changes
     * will just not be saved.
     *
     * @param $form
     */
    protected function fixMissingFormStateFromAjax($form, FormStateInterface $form_state)
    {
        foreach ($form as $type_key => $bundle_elements) {
            if (!is_array($bundle_elements)) {
                continue;
            }

            foreach ($bundle_elements as $entity_bundle_name => $elements) {
                if (!is_array($elements)) {
                    continue;
                }

                if (isset($elements['edit']) && is_array($elements['edit'])) {
                    if (isset($_POST[$type_key][$entity_bundle_name]['edit']) && '1' === $_POST[$type_key][$entity_bundle_name]['edit']) {
                        $form_state->setValue([
                            $type_key,
                            $entity_bundle_name,
                            'edit',
                        ], '1');
                    }
                }

                if (isset($elements['fields']) && is_array($elements['fields'])) {
                    if (isset($_POST[$type_key][$entity_bundle_name]['fields']['advanced']['show-all']) && '1' === $_POST[$type_key][$entity_bundle_name]['fields']['advanced']['show-all']) {
                        $form_state->setValue([
                            $type_key,
                            $entity_bundle_name,
                            'fields',
                            'advanced',
                            'show-all',
                        ], '1');
                    }
                }
            }
        }
    }

    /**
     * Should we display this bundle open or not?
     *
     * @param $type_key
     * @param $entity_bundle_name
     *
     * @return bool
     */
    protected function isBundleOpen(FormStateInterface $form_state, $type_key, $entity_bundle_name)
    {
        $values = $form_state->getValues();

        return isset($values[$type_key][$entity_bundle_name]['handler']) && 'ignore' !== $values[$type_key][$entity_bundle_name]['handler'];
    }

    /**
     * {@inheritdoc}
     */
    protected function actions(array $form, FormStateInterface $form_state)
    {
        if (!$this->getCurrentFormType()) {
            return [];
        }

        $element = parent::actions($form, $form_state);
        $element['submit']['#value'] = $this->t('Save and export');
        $element['save_without_export'] = [
            '#type' => 'submit',
            '#value' => $this->t('Save without export'),
            '#submit' => ['::submitForm', '::save'],
        ];

        return $element;
    }

    /**
     * Disable form elements which are overridden.
     */
    private function disableOverridenConfigs(array &$form)
    {
        global $config;
        $config_name = 'cms_content_sync.cms_content_sync.'.$form['id']['#default_value'];

        // If the default overrides aren't used check if a
        // master / subsite setting is used.
        if (!isset($config[$config_name]) || empty($config[$config_name])) {
            // Is this site a master site? It is a subsite by default.
            $environment = 'subsite';
            if ($this->configFactory->get('config_split.config_split.cms_content_sync_master')
                ->get('status')) {
                $environment = 'master';
            }
            $config_name = 'cms_content_sync.sync.'.$environment;
        }
        $fields = Element::children($form);
        foreach ($fields as $field_key) {
            if ($this->configIsOverridden($field_key, $config_name)) {
                $form[$field_key]['#disabled'] = 'disabled';
                $form[$field_key]['#value'] = $this->configFactory->get($config_name)
                    ->get($field_key);
                unset($form[$field_key]['#default_value']);
            }
        }
    }

    /**
     * Check if a config is overridden.
     *
     * Right now it only checks if the config is in the $config-array (overridden
     * by the settings.php)
     *
     * @param string $config_key
     *                            The configuration key
     * @param string $config_name
     *                            The configuration name
     *
     * @return bool
     *
     * @todo take care of overriding by modules and languages
     */
    private function configIsOverridden($config_key, $config_name)
    {
        global $config;

        return isset($config[$config_name][$config_key]);
    }

    /**
     * Ask the Sync Core to ping the site for all required methods and show an
     * error in the form if the site doesn't respond in time or with an error
     * code.
     *
     * @param string $sync_core_url
     *
     * @throws \Exception
     *
     * @return bool|TranslatableMarkup
     */
    private function validateSyncCoreAccessToSite($sync_core_url)
    {
        $methods = [
            SyncCoreClient::METHOD_POST,
            SyncCoreClient::METHOD_PATCH,
            SyncCoreClient::METHOD_DELETE,
        ];

        $auth_type = ContentSyncSettings::getInstance()->getAuthenticationType();

        $export_url = ContentSyncSettings::getInstance()->getSiteBaseUrl();

        $authentication_provider = AuthenticationByUser::getInstance();

        if (IApplicationInterface::AUTHENTICATION_TYPE_BASIC_AUTH == $auth_type) {
            $authentication = [
                'type' => 'basic_auth',
                'username' => $authentication_provider->getUsername(),
                'password' => $authentication_provider->getPassword(),
                'base_url' => $export_url,
            ];
        } else {
            $authentication = [
                'type' => 'drupal8_services',
                'username' => $authentication_provider->getUsername(),
                'password' => $authentication_provider->getPassword(),
                'base_url' => $export_url,
            ];
        }

        $client = SyncCoreFactory::getSyncCore($sync_core_url);

        foreach ($methods as $method) {
            try {
                $client->requestPing($export_url, $method, $authentication);
            } catch (ForbiddenException $e) {
                return $this->t('The Sync Core could not authenticate against this site. Please check that the Content Sync REST interface allows the configured authentication type and that the Content Sync user still exists. Message: @message', ['@message' => $e->getMessage()]);
            } catch (NotFoundException $e) {
                return $this->t('The Sync Core did not receive a valid response from this site for the method %method. Please configure inbound traffic to be allowed from the <a href="https://edge-box.atlassian.net/wiki/spaces/SUP/pages/143982665/Technical+Requirements">Sync Core IP addresses</a> to this site. Message: @message', [
                    '@message' => $e->getMessage(),
                    '%method' => $method,
                ]);
            } catch (BadRequestException $e) {
                return $this->t('The Sync Core could not reach this site because the URL of this site is invalid. Please update the site base URL in the Content Sync Settings tab. Raw IP addresses and localhost domain names are not allowed. Message: @message', ['@message' => $e->getMessage()]);
            } catch (SyncCoreException $e) {
                return $this->t('The Sync Core could not reach this site for the method %method. Please configure inbound traffic to be allowed from the <a href="https://edge-box.atlassian.net/wiki/spaces/SUP/pages/143982665/Technical+Requirements">Sync Core IP addresses</a> to this site. Message: @message', [
                    '@message' => $e->getMessage(),
                    '%method' => $method,
                ]);
            }
        }

        return false;
    }
}
