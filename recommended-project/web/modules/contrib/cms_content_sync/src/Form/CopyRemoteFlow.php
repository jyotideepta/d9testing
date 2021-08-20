<?php

namespace Drupal\cms_content_sync\Form;

use Drupal\cms_content_sync\Controller\Migration;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncCoreInterface\SyncCoreFactory;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Serialization\Yaml;
use EdgeBox\SyncCore\Exception\SyncCoreException;
use EdgeBox\SyncCore\V1\Helper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for the Pool add and edit forms.
 */
class CopyRemoteFlow extends EntityForm
{
    /**
     * @var string STEP_SYNC_CORE Select a Sync Core to get an existing configuration from
     */
    public const STEP_SYNC_CORE = 'sync-core';
    /**
     * @var string STEP_POOLS Select the pools you want to list known Flows for
     */
    public const STEP_POOLS = 'pools';
    /**
     * @var string STEP_FLOW_LIST Select the flow to copy
     */
    public const STEP_FLOW_LIST = 'flows';
    /**
     * @var string STEP_FLOW_IMPORT Import the selected flow and apply the given changes
     */
    public const STEP_FLOW_IMPORT = 'flow';
    /**
     * @var string CONTAINER_ID The element ID of the container that's replaced with every AJAX request
     */
    public const CONTAINER_ID = 'copy-form-container';
    /**
     * The config storage.
     *
     * @var \Drupal\Core\Config\StorageInterface
     */
    protected $configStorage;
    /**
     * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
     */
    protected $bundleInfoService;
    /**
     * @var \EdgeBox\SyncCore\Exception\SyncCoreException
     */
    protected $syncCoreError;

    /**
     * Constructs a new ConfigSingleImportForm.
     *
     * @param \Drupal\Core\Config\StorageInterface $config_storage
     *                                                             The config storage
     */
    public function __construct(StorageInterface $config_storage, EntityTypeBundleInfoInterface $bundle_info_service)
    {
        $this->configStorage = $config_storage;
        $this->bundleInfoService = $bundle_info_service;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
      $container->get('config.storage'),
      $container->get('entity_type.bundle.info')
    );
    }

    /**
     * Return next form.
     *
     * @param array $form
     *
     * @return array the bundle settings
     */
    public function ajaxReturn($form, FormStateInterface $form_state)
    {
        return $form['elements'];
    }

    /**
     * Rebuild form for next step.
     *
     * @param array $form
     */
    public function searchFlows($form, FormStateInterface $form_state)
    {
        $form_state->setRebuild();
    }

    /**
     * Rebuild form for next step.
     *
     * @param array $form
     */
    public function selectFlow($form, FormStateInterface $form_state)
    {
        $trigger = $form_state->getTriggeringElement();
        $flow_id = $trigger['#flow_id'];

        $form_state->setValue('flow', $flow_id);

        $form_state->setRebuild();
    }

    /**
     * {@inheritdoc}
     */
    public function form(array $form, FormStateInterface $form_state)
    {
        $form = parent::form($form, $form_state);

        $form['#tree'] = false;

        $step = $this->getCurrentFormStep($form_state);

        if (self::STEP_SYNC_CORE === $step) {
            $elements = $this->syncCoreForm($form, $form_state);
            $form['#method'] = 'get';
        } elseif (self::STEP_POOLS === $step) {
            $elements = $this->poolsForm($form, $form_state);
        } elseif (self::STEP_FLOW_LIST === $step) {
            $elements = $this->flowListForm($form, $form_state);
        } else {
            $elements = $this->flowImportForm($form, $form_state);
        }

        $form['elements'] = array_merge([
            '#prefix' => '<div id="'.self::CONTAINER_ID.'">',
            '#suffix' => '</div>',
            'step' => [
                '#type' => 'hidden',
                '#value' => $step,
            ],
        ], $elements);

        return $form;
    }

    /**
     * Validate format of input fields and make sure the Sync Core backend is
     * accessible to actually update it.
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        parent::validateForm($form, $form_state);

        $step = $this->getLastFormStep($form_state);

        if (self::STEP_FLOW_IMPORT === $step) {
            $id = $form_state->getValue('id');
            if (!empty(Flow::getAll(false)[$id])) {
                $form_state->setError($form['elements']['id'], $this->t('A Flow with this ID already exists on this site. Please change it.'));
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function save(array $form, FormStateInterface $form_state)
    {
        $v2 = Migration::alwaysUseV2();
        $url = $v2 ? null : ($form_state->getValue('url') ? $form_state->getValue('url') : $_GET['url']);
        $flow = $form_state->getValue('flow');

        $flow = $this->getFlowConfig($url, $flow);
        $data = Yaml::decode($flow->getConfig());

        $sync_entities = $data['sync_entities'];

        $entity_types = $this->bundleInfoService->getAllBundleInfo();

        // Remove entity type and field configurations that don't exist locally.
        foreach ($sync_entities as $name => $settings) {
            $parts = explode('-', $name);
            list($entity_type_name, $bundle_name) = $parts;

            if (empty($entity_types[$entity_type_name][$bundle_name])) {
                unset($sync_entities[$name]);
            }
        }

        $values = $form_state->getValues();

        // First replace pool settings (as we might be about to swap the whole push/pull settings below)
        foreach ($values as $name => $setting) {
            $parts = explode('_', $name);
            $action = $parts[0];

            if ('export' === $action || 'import' === $action) {
                $subaction = $parts[2];
                if ('pool' !== $subaction) {
                    continue;
                }

                $sync_setting = $parts[1];

                $old_pool_setting = $parts[3];
                $old_pool = implode('_', array_slice($parts, 4));
                $setting_parts = explode('_', $setting);
                $new_pool_setting = $setting_parts[0];
                $new_pool = implode('_', array_slice($setting_parts, 1));

                foreach ($sync_entities as $sync_name => $sync_settings) {
                    // We only care for entity type settings.
                    if (1 !== substr_count($sync_name, '-')) {
                        continue;
                    }

                    if ($sync_settings[$action] !== $sync_setting) {
                        continue;
                    }

                    if (empty($sync_settings[$action.'_pools'][$old_pool]) || $sync_settings[$action.'_pools'][$old_pool] !== $old_pool_setting) {
                        continue;
                    }

                    $sync_entities[$sync_name][$action.'_pools'][$new_pool] = $new_pool_setting;
                    if ($old_pool !== $new_pool && !empty($sync_entities[$sync_name][$action.'_pools'][$old_pool])) {
                        // If someone uses it to replace A with B *AND* B with A, then this will break it depending on the order of
                        // the array. But we don't care about that use case yet.
                        // Changing this also means we have to care about circular references and run it X times where X is the
                        // number of pool + action associations possible.
                        unset($sync_entities[$sync_name][$action.'_pools'][$old_pool]);
                    }
                }
            }
        }

        $action_reverse = [
            'export' => [
                PushIntent::PUSH_DISABLED => PullIntent::PULL_DISABLED,
                PushIntent::PUSH_AUTOMATICALLY => PullIntent::PULL_AUTOMATICALLY,
                PushIntent::PUSH_MANUALLY => PullIntent::PULL_MANUALLY,
                PushIntent::PUSH_AS_DEPENDENCY => PullIntent::PULL_AS_DEPENDENCY,
            ],
            'import' => [
                PullIntent::PULL_DISABLED => PushIntent::PUSH_DISABLED,
                PullIntent::PULL_AUTOMATICALLY => PushIntent::PUSH_AUTOMATICALLY,
                PullIntent::PULL_MANUALLY => PushIntent::PUSH_MANUALLY,
                PullIntent::PULL_AS_DEPENDENCY => PushIntent::PUSH_AS_DEPENDENCY,
            ],
        ];

        // Next replace the actual sync settings. This might include swapping push and pull completely.
        foreach ($values as $name => $setting) {
            $parts = explode('_', $name);
            $action = $parts[0];

            if ('export' === $action || 'import' === $action) {
                $subaction = $parts[2];
                if ('becomes' !== $subaction) {
                    continue;
                }

                $sync_setting = $parts[1];
                $setting_parts = explode('_', $setting);
                $new_action = $setting_parts[0];
                $new_sync_setting = $setting_parts[1];

                foreach ($sync_entities as $sync_name => $sync_settings) {
                    // We only care for entity type settings.
                    if (1 !== substr_count($sync_name, '-')) {
                        continue;
                    }

                    if ($sync_settings[$action] !== $sync_setting) {
                        continue;
                    }

                    // Swap pools in case push+pull changed; This works because $sync_settings is not a reference but a copy.
                    $sync_entities[$sync_name][$new_action.'_pools'] = $sync_settings[$action.'_pools'];
                    if (!empty($sync_settings[$new_action.'_pools'])) {
                        $sync_entities[$sync_name][$action.'_pools'] = $sync_settings[$new_action.'_pools'];
                    }

                    // Swap "Push / Pull deletion" settings as well.
                    if (!empty($sync_settings[$action.'_deletion_settings'])) {
                        $sync_entities[$sync_name][$new_action.'_deletion_settings'][$new_action.'_deletion'] = $sync_settings[$action.'_deletion_settings'][$action.'_deletion'];
                    }
                    if (!empty($sync_settings[$new_action.'_deletion_settings'])) {
                        $sync_entities[$sync_name][$action.'_deletion_settings'][$action.'_deletion'] = $sync_settings[$new_action.'_deletion_settings'][$new_action.'_deletion'];
                    }

                    // Set new sync setting (e.g. manually becomes automatically)
                    $sync_entities[$sync_name][$new_action] = $new_sync_setting;
                    // In case push and pull was swapped, we want to actually swap these settings...
                    if ($action !== $new_action) {
                        // ...unless this will also be handled by our foreach. In this case we leave it untouched.
                        if (empty($values[$new_action.'_'.$new_sync_setting.'_becomes'])) {
                            // So now when the user swaps pull automatically with Push automatically for example with Push being
                            // disabled in the flow that's copied, this will make sure that now the pull is disabled.
                            $sync_entities[$sync_name][$action] = $action_reverse[$new_action][$sync_settings[$new_action]];
                        }

                        // Swap field push and pull settings if required.
                        foreach ($sync_entities as $field_sync_name => $field_sync_settings) {
                            // We only care for field settings for this entity type.
                            if (substr($field_sync_name, 0, strlen($sync_name) + 1) !== $sync_name.'-') {
                                continue;
                            }

                            // Swap settings if push+pull changed; This works because $sync_settings is not a reference but a copy.
                            if (!empty($field_sync_settings[$new_action])) {
                                $sync_entities[$field_sync_name][$action] = $action_reverse[$new_action][$field_sync_settings[$new_action]];
                            }
                            if (!empty($field_sync_settings[$action])) {
                                $sync_entities[$field_sync_name][$new_action] = $action_reverse[$new_action][$field_sync_settings[$action]];
                            }
                        }
                    }
                }
            }
        }

        $values['id'] = $form_state->getValue('id');
        $values['name'] = $form_state->getValue('name');
        $values['sync_entities'] = $sync_entities;

        $flow = Flow::create(
            $values
        );

        $flow->save();

        // Make sure that the flow is now edited, saved and then exported. This is critical because some settings require
        // new defaults, e.g. if you change pull to push you want to set "Push referenced entities" settings now.
        \Drupal::request()->query->remove('destination');

        $form_state->setRedirect(
            'entity.cms_content_sync_flow.edit_form',
            ['cms_content_sync_flow' => $flow->id()],
            [
                'query' => [
                    'open' => '',
                ],
            ]
        );
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

    /**
     * Return the current step of our multi-step form.
     *
     * @return string
     */
    protected function getCurrentFormStep(FormStateInterface $form_state)
    {
        if (!Migration::alwaysUseV2()) {
            if (empty($_GET['url'])) {
                return self::STEP_SYNC_CORE;
            }

            try {
                $client = SyncCoreFactory::getSyncCore($_GET['url']);
                $client->getReportingService()->getStatus();
            } catch (SyncCoreException $e) {
                $this->syncCoreError = $e;

                return self::STEP_SYNC_CORE;
            }
        }

        if (empty($form_state->getValue('pools'))) {
            return self::STEP_POOLS;
        }

        if (empty($form_state->getValue('flow'))) {
            return self::STEP_FLOW_LIST;
        }

        return self::STEP_FLOW_IMPORT;
    }

    /**
     * Step 1: Select Sync Core or enter new Sync Core URL.
     *
     * @param bool $collapsed
     *
     * @return array the form
     */
    protected function syncCoreForm(array $form, FormStateInterface $form_state)
    {
        $elements['headline'] = [
            '#markup' => '<br><br><h1>Step 1: Pick Sync Core</h1>'.
            '<br><br>',
        ];

        $pools = Pool::getAll();

        $urls = [];
        foreach ($pools as $existing_pool) {
            $url = $existing_pool->getSyncCoreUrl();
            $urls[$url] = Helper::obfuscateCredentials($url);
        }

        if (count($urls)) {
            foreach ($urls as $url => $name) {
                $elements['headline']['#markup'] .= '<a href="?url='.urlencode($url).'">'.$name.'</a><br>';
            }

            $elements['headline']['#markup'] .= '<br><br><h2>-OR-</h2><br><br>';
        }

        $elements['url'] = [
            '#type' => 'url',
            '#default_value' => isset($_GET['url']) ? $_GET['url'] : null,
            '#title' => $this->t('Enter Sync Core URL'),
            '#description' => $this->syncCoreError ? $this->syncCoreError->getMessage() : '',
        ];

        $elements['continue'] = [
            '#type' => 'submit',
            '#value' => $this->t('Connect'),
            '#name' => 'connect',
            '#attributes' => [
                'class' => ['button--primary'],
            ],
        ];

        return $elements;
    }

    /**
     * Step 2: Select pools to look for existing flows for.
     *
     * @param bool $collapsed
     *
     * @return array the form
     */
    protected function poolsForm(array $form, FormStateInterface $form_state)
    {
        $elements['headline'] = [
            '#markup' => '<br><br><h1>Step 2: Select pools</h1>'.
            '<br><br>',
        ];

        $v2 = Migration::alwaysUseV2();
        $url = $v2 ? null : ($form_state->getValue('url') ? $form_state->getValue('url') : $_GET['url']);

        $elements['url'] = [
            '#type' => 'hidden',
            '#value' => $url ?? '',
        ];

        $options = $this->getRemotePools($url);
        $create = $this->getNewRemotePools($options);

        $current = \Drupal::request()->getRequestUri();
        foreach ($create as $id => $name) {
            $link = Link::createFromRoute(
                $this->t('here'),
                'entity.cms_content_sync_pool.add_form',
                [],
                [
                    'query' => [
                        'backend_url' => $url,
                        'id' => $id,
                        'name' => $name,
                        'destination' => $current,
                    ],
                ]
            );
            $options[$id] .= ' ('.$this->t('<em>click</em> @here <em>to use in this site</em>', ['@here' => $link->toString()]).')';
        }

        $elements['pools'] = [
            '#type' => 'checkboxes',
            '#options' => $options,
            '#description' => $this->t("Filter by pools. This will filter the list of Flows you see next and exclude any that don't use all of the pools you selected here."),
        ];

        $elements['continue'] = [
            '#prefix' => '<br><br>',
            '#type' => 'submit',
            '#value' => $this->t('Search flows'),
            '#submit' => ['::searchFlows'],
            '#name' => 'flows',
            '#attributes' => [
                'class' => ['button--primary'],
            ],
            '#ajax' => [
                'callback' => '::ajaxReturn',
                'wrapper' => self::CONTAINER_ID,
                'method' => 'replace',
                'effect' => 'fade',
                'progress' => [
                    'type' => 'throbber',
                    'message' => 'loading settings...',
                ],
            ],
        ];

        return $elements;
    }

    /**
     * List all remote pools that aren't used locally yet.
     *
     * @param $url
     *
     * @throws \Exception
     *
     * @return array
     */
    protected function getRemotePools($url)
    {
        $client = $url ? SyncCoreFactory::getSyncCore($url) : SyncCoreFactory::getSyncCoreV2();

        return $client
            ->getConfigurationService()
            ->listRemotePools();
    }

    /**
     * Get all pools that exist in the Sync Core but *NOT* locally.
     *
     * @param array $pool_options
     *
     * @return array
     */
    protected function getNewRemotePools($pool_options)
    {
        $pools = Pool::getAll();

        $options = [];
        foreach ($pool_options as $id => $name) {
            // Exist locally, so can't be selected.
            if (isset($pools[$id])) {
                continue;
            }

            $options[$id] = $name;
        }

        return $options;
    }

    /**
     * Step 3: Select the remote Flow to import now.
     *
     * @param bool $collapsed
     *
     * @return array the form
     */
    protected function flowListForm(array $form, FormStateInterface $form_state)
    {
        $elements['headline'] = [
            '#markup' => '<br><br><h1>Step 3: Select remote Flow</h1>'.
            '<div class="messages messages--status">'.
            $this->t('Select the Flow to copy.<br><br>In the next step you can still replace the pools that are used and switch push/pull from manual to automatic and vice versa.<br>You can also completely switch push and pull. This is especially useful for Content Staging setups where you have one exporting and one pulling site where the config must be mirrored.').
            '</div>'.
            '<br><br>',
        ];

        $v2 = Migration::alwaysUseV2();
        $url = $v2 ? null : ($form_state->getValue('url') ? $form_state->getValue('url') : $_GET['url']);
        $pools = $form_state->getValue('pools');

        $elements['url'] = [
            '#type' => 'hidden',
            '#value' => $url ?? '',
        ];

        $elements['pools'] = [
            '#type' => 'hidden',
            '#value' => $pools,
        ];

        $module_info = \Drupal::service('extension.list.module')->getExtensionInfo('cms_content_sync');

        $client = $url ? SyncCoreFactory::getSyncCore($url) : SyncCoreFactory::getSyncCoreV2();
        $list = $client
            ->getConfigurationService()
            ->listRemoteFlows($module_info['version'] ? $module_info['version'] : 'dev');

        foreach ($pools as $pool_id => $selected) {
            if (!$selected) {
                continue;
            }

            $list->thatUsePool($pool_id);
        }

        $flows = $list->execute();

        if (!count($flows)) {
            $elements['none'] = [
                '#markup' => '<div class="messages messages--warning">'.
                $this->t('There are no flows exported yet that use the selected pools.').
                '</div>',
            ];
        }

        foreach ($flows as $flow) {
            $id = $flow->getId();

            $elements['flow-'.$id] = [
                '#prefix' => '<p>',
                '#suffix' => '</p>',
                '#markup' => $this->t('Copy %name from %site', ['%name' => $flow->getName(), '%site' => $flow->getSiteName()]),
                'use' => [
                    '#type' => 'submit',
                    '#value' => $this->t('Select'),
                    '#submit' => ['::selectFlow'],
                    '#flow_id' => $id,
                    '#name' => 'flow-'.$id,
                    '#attributes' => [
                        'class' => ['button--primary'],
                    ],
                    '#ajax' => [
                        'callback' => '::ajaxReturn',
                        'wrapper' => self::CONTAINER_ID,
                        'method' => 'replace',
                        'effect' => 'fade',
                        'progress' => [
                            'type' => 'throbber',
                            'message' => 'loading settings...',
                        ],
                    ],
                ],
            ];
        }

        return $elements;
    }

    /**
     * Step 4: Adjust configuration before importing.
     *
     * @return array the form
     */
    protected function flowImportForm(array $form, FormStateInterface $form_state)
    {
        $v2 = Migration::alwaysUseV2();
        $url = $v2 ? null : ($form_state->getValue('url') ? $form_state->getValue('url') : $_GET['url']);
        $pools = $form_state->getValue('pools');
        $flow = $form_state->getValue('flow');

        $elements['url'] = [
            '#type' => 'hidden',
            '#value' => $url ?? '',
        ];
        $elements['pools'] = [
            '#type' => 'hidden',
            '#value' => $pools,
        ];
        $elements['flow'] = [
            '#type' => 'hidden',
            '#value' => $flow,
        ];

        $flow = $this->getFlowConfig($url, $flow);

        $elements['headline'] = [
            '#markup' => '<br><br><h1>Step 4: '.$this->t('Copy %name from %site', ['%name' => $flow->getName(), '%site' => $flow->getSiteName()]).'</h1>'.
            '<div class="messages messages--status">'.
            $this->t('Replace settings of the flow below before importing it. If you want to copy this flow 1:1, just hit Save.<br>You can still edit the Flow and adjust all other settings after importing it.').
            '</div>'.
            '<br><br>',
        ];

        $data = Yaml::decode($flow->getConfig());

        $elements['name'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Name'),
            '#maxlength' => 255,
            '#attributes' => [
                'autofocus' => 'autofocus',
            ],
            '#default_value' => $data['name'],
            '#description' => $this->t('An administrative name describing the workflow intended to be achieved with this synchronization.'),
            '#required' => true,
        ];

        $elements['id'] = [
            '#type' => 'machine_name',
            '#default_value' => $data['id'],
            '#machine_name' => [
                'exists' => [$this, 'exists'],
                'source' => ['name'],
            ],
            '#required' => true,
        ];

        $pushing = [
            PushIntent::PUSH_AUTOMATICALLY => false,
            PushIntent::PUSH_MANUALLY => false,
            PushIntent::PUSH_AS_DEPENDENCY => false,
        ];
        $push_to_pools = [];

        $pulling = [
            PullIntent::PULL_AUTOMATICALLY => false,
            PullIntent::PULL_MANUALLY => false,
            PullIntent::PULL_AS_DEPENDENCY => false,
        ];
        $pull_pool_settings = [];

        foreach ($data['sync_entities'] as $name => $config) {
            // We're only interested in entity type configuration.
            if (substr_count($name, '-') > 1) {
                continue;
            }

            if (empty($config['handler']) || Flow::HANDLER_IGNORE === $config['handler']) {
                continue;
            }

            $pushing[$config['export']] = true;
            if (isset($config['export_pools'])) {
                foreach ($config['export_pools'] as $pool => $setting) {
                    $push_to_pools[$config['export']][$pool][$setting] = true;
                }
            }

            $pulling[$config['import']] = true;
            if (isset($config['import_pools'])) {
                foreach ($config['import_pools'] as $pool => $setting) {
                    $pull_pool_settings[$config['import']][$pool][$setting] = true;
                }
            }
        }
        unset($pushing[PushIntent::PUSH_DISABLED], $pulling[PullIntent::PULL_DISABLED]);

        $options = [
            'export_'.PushIntent::PUSH_AUTOMATICALLY => $this->t('Push all'),
            'export_'.PushIntent::PUSH_MANUALLY => $this->t('Push manually'),
            'export_'.PushIntent::PUSH_AS_DEPENDENCY => $this->t('Push referenced'),
            'import_'.PullIntent::PULL_AUTOMATICALLY => $this->t('Pull all'),
            'import_'.PullIntent::PULL_MANUALLY => $this->t('Pull manually'),
            'import_'.PullIntent::PULL_AS_DEPENDENCY => $this->t('Pull referenced'),
        ];

        $pool_usage_labels = [
            Pool::POOL_USAGE_FORCE => 'Force',
            Pool::POOL_USAGE_ALLOW => 'Allow',
            Pool::POOL_USAGE_FORBID => 'Forbid',
        ];
        $pool_options = [];
        foreach (Pool::getAll() as $id => $pool) {
            $pool_options[Pool::POOL_USAGE_FORCE.'_'.$id] = $this->t('Force pool @pool', ['@pool' => $pool->label()]);
            $pool_options[Pool::POOL_USAGE_ALLOW.'_'.$id] = $this->t('Allow pool @pool', ['@pool' => $pool->label()]);
            $pool_options[Pool::POOL_USAGE_FORBID.'_'.$id] = $this->t('Forbid pool @pool', ['@pool' => $pool->label()]);
        }

        if (in_array(true, $pushing)) {
            $elements['export'] = [
                '#markup' => '<br><br><h2>'.$this->t('Change push to').'</h2>',
            ];

            foreach ($pushing as $type => $does) {
                if (!$does) {
                    continue;
                }

                $id = 'export_'.$type;

                $elements[$id] = [
                    '#type' => 'fieldset',
                    '#title' => $options[$id],
                    $id.'_becomes' => [
                        '#type' => 'select',
                        '#options' => $options,
                        '#required' => true,
                        '#title' => $this->t('Becomes'),
                        '#default_value' => $id,
                    ],
                ];

                foreach ($push_to_pools[$type] as $pool => $pool_settings) {
                    foreach ($pool_settings as $setting => $used) {
                        $elements[$id][$id.'_pool_'.$setting.'_'.$pool] = [
                            '#type' => 'select',
                            '#options' => $pool_options,
                            '#required' => true,
                            '#title' => $this->t('%action pool %pool becomes', ['%pool' => $pool, '%action' => $pool_usage_labels[$setting]]),
                            '#default_value' => $setting.'_'.$pool,
                        ];
                    }
                }
            }
        }

        if (in_array(true, $pulling)) {
            $elements['import'] = [
                '#markup' => '<br><br><h2>'.$this->t('Change pull to').'</h2>',
            ];

            foreach ($pulling as $type => $does) {
                if (!$does) {
                    continue;
                }

                $id = 'import_'.$type;

                $elements[$id] = [
                    '#type' => 'fieldset',
                    '#title' => $options[$id],
                    $id.'_becomes' => [
                        '#type' => 'select',
                        '#options' => $options,
                        '#required' => true,
                        '#title' => $this->t('Becomes'),
                        '#default_value' => $id,
                    ],
                ];

                foreach ($pull_pool_settings[$type] as $pool => $pool_settings) {
                    foreach ($pool_settings as $setting => $used) {
                        $elements[$id][$id.'_pool_'.$setting.'_'.$pool] = [
                            '#type' => 'select',
                            '#options' => $pool_options,
                            '#required' => true,
                            '#title' => $this->t('%action pool %pool becomes', ['%pool' => $pool, '%action' => $pool_usage_labels[$setting]]),
                            '#default_value' => $setting.'_'.$pool,
                        ];
                    }
                }
            }
        }

        $elements['save'] = [
            '#type' => 'submit',
            '#value' => $this->t('Save'),
            '#submit' => ['::save'],
            '#attributes' => [
                'class' => ['button--primary'],
            ],
        ];

        return $elements;
    }

    /**
     * Get a remote Flow config from the Sync Core.
     *
     * @param $url
     * @param $id
     *
     * @return \EdgeBox\SyncCore\Interfaces\Configuration\IRemoteFlow
     */
    protected function getFlowConfig($url, $id)
    {
        $client = $url ? SyncCoreFactory::getSyncCore($url) : SyncCoreFactory::getSyncCoreV2();

        return $client
            ->getConfigurationService()
            ->getRemoteFlow($id);
    }

    /**
     * Return the current step of our multi-step form.
     *
     * @return string
     */
    protected function getLastFormStep(FormStateInterface $form_state)
    {
        $step = $form_state->getValue('step');
        if (empty($step)) {
            return self::STEP_SYNC_CORE;
        }

        return $step;
    }

    /**
     * {@inheritdoc}
     */
    protected function actions(array $form, FormStateInterface $form_state)
    {
        return [];
    }
}
