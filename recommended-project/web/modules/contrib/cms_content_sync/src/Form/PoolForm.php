<?php

namespace Drupal\cms_content_sync\Form;

use Drupal\cms_content_sync\Controller\Migration;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Site\Settings;
use EdgeBox\SyncCore\Exception\SyncCoreException;
use EdgeBox\SyncCore\V1\Helper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for the Pool add and edit forms.
 */
class PoolForm extends EntityForm
{
    /**
     * @var int Defines the max length for the siteID. This must be limited due to the maximum characters allowed for table names within mongo db.
     */
    public const siteIdMaxLength = 20;
    /**
     * @var string STEP_SYNC_CORE Select a Sync Core another pool on this site already uses or enter a new one
     */
    public const STEP_SYNC_CORE = 'sync-core';
    /**
     * @var string STEP_POOL Select pool from existing pools in the Sync Core or enter a new one
     */
    public const STEP_POOL = 'pool';
    /**
     * @var string CONTAINER_ID The element ID of the container that's replaced with every AJAX request
     */
    public const CONTAINER_ID = 'pool-form-container';
    /**
     * @var null|string
     */
    protected $backendUrl;
    /**
     * @var null|string
     */
    protected $configMachineName;
    /**
     * @var null|string
     */
    protected $overwrittenSiteId;

    /**
     * Constructs an PoolForm object.
     *
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
     *                                                                          The entityTypeManager
     * @param \Drupal\Core\Extension\ModuleHandler           $moduleHandler
     *                                                                          The moduleHandler
     */
    public function __construct(EntityTypeManagerInterface $entityTypeManager, ModuleHandler $moduleHandler)
    {
        $this->entityTypeManager = $entityTypeManager;
        $this->moduleHandler = $moduleHandler;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
      $container->get('entity_type.manager'),
      $container->get('module_handler')
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
    public function connectSyncCore($form, FormStateInterface $form_state)
    {
        $form_state->setRebuild();
    }

    /**
     * Rebuild form for next step.
     *
     * @param array $form
     */
    public function checkSiteId($form, FormStateInterface $form_state)
    {
        $form_state->setRebuild();
    }

    /**
     * Rebuild form for next step.
     *
     * @param array $form
     */
    public function createNew($form, FormStateInterface $form_state)
    {
        $form_state->setValue('id', 'new');

        // If we only use ->setValue() the default value in the input element will still be whatever was selected at the radios.
        $data = $form_state->getUserInput();
        $data['id'] = 'new';
        $form_state->setUserInput($data);

        $form_state->setRebuild();
    }

    /**
     * {@inheritdoc}
     */
    public function form(array $form, FormStateInterface $form_state)
    {
        $form = parent::form($form, $form_state);

        $form['#tree'] = false;

        $defaults = $this->getDefaults($form_state);
        if (!empty($defaults['backend_url'])) {
            $form['default_backend_url'] = [
                '#type' => 'hidden',
                '#value' => $defaults['backend_url'],
            ];
        }
        if (!empty($defaults['name'])) {
            $form['default_name'] = [
                '#type' => 'hidden',
                '#value' => $defaults['name'],
            ];
        }
        if (!empty($defaults['id'])) {
            $form['default_id'] = [
                '#type' => 'hidden',
                '#value' => $defaults['id'],
            ];
        }

        /**
         * @var \Drupal\cms_content_sync\Entity\Pool $pool
         */
        $pool = $this->entity;

        // Check if the site id or backend_url got set within the settings*.php.
        if (!is_null($pool->id)) {
            $this->configMachineName = $pool->id;
            $cms_content_sync_settings = Settings::get('cms_content_sync');
            if (!is_null($cms_content_sync_settings) && isset($cms_content_sync_settings['pools'][$pool->id]['backend_url'])) {
                $this->backendUrl = $cms_content_sync_settings['pools'][$pool->id]['backend_url'];
            }
        }
        if (!isset($this->configMachineName)) {
            $this->configMachineName = '<machine_name_of_the_configuration>';
        }

        $step = $this->getCurrentFormStep($form_state);

        if (self::STEP_SYNC_CORE === $step) {
            $elements = $this->syncCoreForm($form, $form_state);
        } else {
            $elements = $this->poolForm($form, $form_state);
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

        /**
         * @var Pool $entity
         */
        $entity = $this->entity;
        $entity->backend_url = $form_state->getValue('backend_url');
        $allowed_length = 0;
        if (!$entity->useV2()) {
            try {
                $client = $entity->getClient();
                $client->getReportingService()->getStatus();

                // In MongoDB (used by the Sync Core, the name of the database + collection name mustn't be longer than 120 characters)
                $longest_entity_name = 0;
                $bundleInfoService = \Drupal::service('entity_type.bundle.info');
                $entity_types = $bundleInfoService->getAllBundleInfo();
                foreach ($entity_types as $type_key => $entity_type) {
                    foreach ($entity_type as $entity_bundle_name => $entity_bundle) {
                        $length = strlen($type_key) + strlen($entity_bundle_name) + 1;
                        if ($length > $longest_entity_name) {
                            $longest_entity_name = $length;
                        }
                    }
                }

                $allowed_length = 120 - 7;

                // Full name is formatted like this: [database].drupal-[pool]-[site]-[entity]-[bundle].
                $allowed_length = $allowed_length - 6 - 1 - 1 - 1 - $longest_entity_name - 1;
            } catch (SyncCoreException $e) {
                $form_state->setErrorByName('backend_url', $this->t('The Sync Core is not accessible (did not respond with 200 OK). Please configure your outbound traffic to allow traffic to the Sync Core. The error message is @message', ['@message' => $e->getMessage()]));
            }
        }

        if (self::STEP_POOL === $step) {
            $api = $form_state->getValue('id');

            if (!preg_match('@^([a-z0-9\-_]+)$@', $api)) {
                $form_state->setErrorByName('id', $this->t('Please only use letters, numbers and dashes.'));
            }

            if ('drupal' == $api || 'api-unify' == $api) {
                $form_state->setErrorByName('api', $this->t('This name is reserved.'));
            }

            $site_id = $form_state->getValue('site_id');
            $length = strlen($api) + strlen($site_id);

            if ($allowed_length && $length > $allowed_length) {
                $form_state->setErrorByName('id', $this->t('The Pool machine name + site id combined mustn\'t be longer than @allowed characters.', ['@allowed' => $allowed_length]));
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function save(array $form, FormStateInterface $form_state)
    {
        /**
         * @var \Drupal\cms_content_sync\Entity\Pool $pool
         */
        $pool = $this->entity;

        if (!$pool->label()) {
            $pool->label = $this->getRemotePools($form_state)[$pool->id()];
        }

        $status = $pool->save();

        if ($status) {
            if (empty(Flow::getAll())) {
                $link = Link::createFromRoute(
                    $this->t('Create a Flow'),
                    'entity.cms_content_sync_flow.add_form'
                )->toString();
                \Drupal::messenger()->addStatus(
                    $this->t('Saved Pool %label. Well done! @create to continue the setup.', [
                        '%label' => $pool->label(),
                        '@create' => $link,
                    ])
                );
                \Drupal::messenger()->addStatus(
                    $this->t('If you have connected another site already, @copy. Mirroring means you can simply swap the push and pull settings.', [
                        '@copy' => Link::createFromRoute('copy or mirror the configuration from another site', 'entity.cms_content_sync_flow.copy_remote')->toString(),
                    ])
                );
            } else {
                \Drupal::messenger()->addStatus(
                    $this->t('Saved Pool %label.', [
                        '%label' => $pool->label(),
                    ])
                );
            }
        } else {
            \Drupal::messenger()->addStatus(
                $this->t('The %label Pool could not be saved.', [
                    '%label' => $pool->label(),
                ])
            );
        }

        // Make sure that the export is executed.
        $destination = \Drupal::request()->query->get('destination');
        \Drupal::request()->query->remove('destination');

        // Keep destination for after the export.
        $options = [];
        if (!empty($destination)) {
            $options['query']['destination'] = $destination;
        }

        $form_state->setRedirect('entity.cms_content_sync_pool.export', ['cms_content_sync_pool' => $this->entity->id()], $options);
    }

    /**
     * Helper function to check whether an Pool configuration entity exists.
     *
     * @param mixed $id
     */
    public function exist($id)
    {
        $entity = $this->entityTypeManager->getStorage('cms_content_sync_pool')->getQuery()
            ->condition('id', $id)
            ->execute();

        return (bool) $entity;
    }

    /**
     * Get default values that were provided in the URL.
     *
     * @return array
     */
    protected function getDefaults(FormStateInterface $form_state)
    {
        return [
            'backend_url' => empty($_GET['backend_url']) ? $form_state->getValue('default_backend_url') : $_GET['backend_url'],
            'name' => empty($_GET['name']) ? $form_state->getValue('default_name') : $_GET['name'],
            'id' => empty($_GET['id']) ? $form_state->getValue('default_id') : $_GET['id'],
        ];
    }

    /**
     * Return the current step of our multi-step form.
     *
     * @return string
     */
    protected function getCurrentFormStep(FormStateInterface $form_state)
    {
        /**
         * @var \Drupal\cms_content_sync\Entity\Pool $pool
         */
        $pool = $this->entity;

        if (!$pool->getSyncCoreUrl() && !$form_state->getValue('backend_url')) {
            return self::STEP_SYNC_CORE;
        }

        return self::STEP_POOL;
    }

    /**
     * Step 2: Enter or select site ID.
     *
     * @param bool $collapsed
     *
     * @return array the form
     */
    protected function syncCoreForm(array $form, FormStateInterface $form_state, $collapsed = false)
    {
        /**
         * @var \Drupal\cms_content_sync\Entity\Pool $pool
         */
        $pool = $this->entity;

        if (Migration::alwaysUseV2()) {
            return [];
        }

        if ($collapsed) {
            $elements['backend_url'] = [
                '#type' => 'hidden',
                '#value' => $form_state->getValue('backend_url') ? $form_state->getValue('backend_url') : $pool->getSyncCoreUrl(),
            ];

            return $elements;
        }

        $elements['headline'] = [
            '#markup' => '<br><br><h1>Step 1: Pick Sync Core</h1>'.
            '<div class="messages messages--status">'.
            $this->t("The Sync Core is the central distribution service used to syndicate content between sites. If you don't have one yet, just <a href='mailto:info@cms-content-sync.io?subject=Evaluation%20Sync%20Core&body=Please%20provide%20me%20an%20evaluation%20Sync%20Core'>contact us</a>. <br>
<br>
<strong>Want to learn more?</strong><br>
Use <a href='https://www.cms-content-sync.io/content-staging/'>Content Staging</a> to publish content from a private site to a public site. Pricing for Content Staging is documented <a href='https://www.cms-content-sync.io/content-sync-for-drupal/content-staging-for-drupal/'>here</a>.<br>
Use <a href='https://www.cms-content-sync.io/content-syndication/'>Content Syndication</a> to connect many public sites to share content between them. Pricing for Content Syndication is documented <a href='https://www.cms-content-sync.io/content-sync-for-drupal/content-syndication-for-drupal/'>here</a>.
").
            '</div><br><br>',
        ];

        $default_backend_url = $this->getDefaults($form_state)['backend_url'];

        $pools = Pool::getAll();
        $urls = [];
        $default_auth = null;
        foreach ($pools as $existing_pool) {
            $url = $existing_pool->getSyncCoreUrl();
            $urls[$url] = Helper::obfuscateCredentials($url);
        }

        if (count($urls) && !isset($_GET['add-sync-core']) && empty($default_backend_url)) {
            $url = $pool->getSyncCoreUrl();

            if ($url) {
                $urls[$url] = Helper::obfuscateCredentials($url);
            }

            $elements['backend_url'] = [
                '#type' => 'radios',
                '#title' => $this->t('Sync Core URL'),
                '#default_value' => $url ? $url : array_slice(array_keys($urls), 0, 1)[0],
                '#description' => $this->t('The backend url can be overwritten within your environment specific settings.php file by using <i>@settings</i>.', [
                    '@settings' => '$settings["cms_content_sync"]["pools"]["'.$this->configMachineName.'"]["backend_url"] = "http://cms-content-sync-example:8691/rest"',
                    '@config_machine_name' => $this->configMachineName,
                ]).'<br><a href="?add-sync-core">Add new</a>',
                '#options' => $urls,
                '#required' => true,
            ];
        } else {
            $elements['backend_url'] = [
                '#type' => 'url',
                '#title' => $this->t('Sync Core URL'),
                '#default_value' => empty($default_backend_url) ? $pool->getSyncCoreUrl() : $default_backend_url,
                '#description' => $this->t('The backend url can be overwritten within your environment specific settings.php file by using <i>@settings</i>.', [
                    '@settings' => '$settings["cms_content_sync"]["pools"]["'.$this->configMachineName.'"]["backend_url"] = "http://cms-content-sync-example.de:8691/rest"',
                    '@config_machine_name' => $this->configMachineName,
                ]),
                '#required' => true,
            ];
        }

        // If the backend_url is set within the settings.php,
        // the form field is disabled.
        if (isset($this->backendUrl)) {
            $elements['backend_url']['#disabled'] = true;
            $elements['backend_url']['#default_value'] = $this->backendUrl;
            $elements['backend_url']['#description'] = $this->t('The backend url is set within the environment specific settings.php file.');
        }

        $elements['continue'] = [
            '#prefix' => '<br><br>',
            '#type' => 'submit',
            '#submit' => ['::connectSyncCore'],
            '#value' => $this->t('Connect'),
            '#name' => 'connect',
            // '#limit_validation_errors' => [],
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
     * Step 3: Select an existing pool or create a new one.
     *
     * @throws \Exception
     *
     * @return array the form
     */
    protected function poolForm(array $form, FormStateInterface $form_state)
    {
        $elements = $this->syncCoreForm($form, $form_state, true);

        $elements['headline'] = [
            '#markup' => '<br><br><h1>Step 3: Pool properties</h1>',
        ];

        /**
         * @var \Drupal\cms_content_sync\Entity\Pool $pool
         */
        $pool = $this->entity;

        $default_name = $this->getDefaults($form_state)['name'];
        $default_id = $this->getDefaults($form_state)['id'];

        $options = $pool->isNew() && empty($default_id) ? $this->getRemotePools($form_state) : [];

        if (count($options) && !$form_state->getValue('id')) {
            $elements['id'] = [
                '#type' => 'radios',
                '#title' => $this->t('Existing pools'),
                '#required' => true,
                '#default_value' => array_slice(array_keys($options), 0, 1)[0],
                '#options' => $options,
            ];
        } else {
            $elements['label'] = [
                '#type' => 'textfield',
                '#title' => $this->t('Label'),
                '#maxlength' => 255,
                '#default_value' => empty($default_name) ? ($form_state->getValue('label') ? $form_state->getValue('label') :
                  ($pool->label() ? $pool->label() : (empty(Pool::getAll()['content']) ? $this->t('Content') : ''))) : $default_name,
                '#description' => $this->t("The pool name. If you aren't working with multiple pools just leave it as it is."),
                '#required' => true,
            ];

            $elements['id'] = [
                '#type' => 'machine_name',
                '#default_value' => empty($default_id) ? ($form_state->getValue('id') ? $form_state->getValue('id') :
                  ($pool->id() ? $pool->id() : (empty(Pool::getAll()['content']) ? 'content' : ''))) : $default_id,
                '#machine_name' => [
                    'exists' => [$this, 'exist'],
                ],
                '#description' => $this->t("A unique ID that must be identical on all sites that want to connect to this pool. If you aren't working with multiple pools just leave it as it is."),
                '#disabled' => !$pool->isNew(),
            ];
        }

        // AJAX? Show submit buttons inline.
        if ($form_state->getTriggeringElement()) {
            $actions = $this->actions($form, $form_state);
            $actions['submit']['#attributes']['class'][] = 'button--primary';
            $elements = array_merge($elements, $actions);
        }

        if (!isset($elements['label'])) {
            $elements['create'] = [
                '#prefix' => '<br><br>',
                '#type' => 'submit',
                '#submit' => ['::createNew'],
                '#value' => $this->t('Create new'),
                '#name' => 'create',
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
        }

        return $elements;
    }

    /**
     * List all remote pools that aren't used locally yet.
     *
     * @throws \Exception
     */
    protected function getRemotePools(FormStateInterface $form_state)
    {
        /**
         * @var Pool $entity
         */
        $entity = $this->entity;
        $client = $entity->getClient();
        $pools = $client->getConfigurationService()->listRemotePools();

        $local_pools = Pool::getAll();

        foreach ($pools as $id => $name) {
            // Already exists locally.
            if (isset($local_pools[$id])) {
                unset($pools[$id]);
            }
        }

        return $pools;
    }

    /**
     * {@inheritdoc}
     */
    protected function actions(array $form, FormStateInterface $form_state)
    {
        $step = $this->getCurrentFormStep($form_state);

        if (self::STEP_POOL !== $step) {
            return [];
        }

        return parent::actions($form, $form_state);
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
}
