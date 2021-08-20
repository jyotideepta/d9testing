<?php

namespace Drupal\cms_content_sync\Form;

use Drupal\cms_content_sync\Controller\ContentSyncSettings;
use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncCoreInterface\SyncCoreFactory;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\RfcLogLevel;
use EdgeBox\SyncCore\Interfaces\ISyncCore;
use EdgeBox\SyncCore\V1\Entity\Entity;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Content Sync advanced debug form.
 */
class DebugForm extends ConfigFormBase
{
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
     * Constructs an object.
     *
     * @param \Drupal\Core\Config\ConfigFactoryInterface        $config_factory
     *                                                                                The factory for configuration objects
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface    $entity_type_manager
     *                                                                                The entity query
     * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundle_info_service
     *                                                                                The bundle info service
     * @param \Drupal\Core\Entity\EntityFieldManager            $entity_field_manager
     *                                                                                The entity field manager
     */
    public function __construct(
        ConfigFactoryInterface $config_factory,
        EntityTypeManagerInterface $entity_type_manager,
        EntityTypeBundleInfoInterface $bundle_info_service,
        EntityFieldManager $entity_field_manager
    ) {
        parent::__construct($config_factory);

        $this->entityTypeManager = $entity_type_manager;
        $this->bundleInfoService = $bundle_info_service;
        $this->entityFieldManager = $entity_field_manager;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager')
    );
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'cms_content_sync_debug_form';
    }

    /**
     * Display debug output for a given entity to analyze it's sync structure.
     *
     * @param array $form
     *
     * @return AjaxResponse|array
     *                            The debug output table
     */
    public function inspectEntity($form, FormStateInterface $form_state)
    {
        $entity_type = $form_state->getValue([
            'cms_content_sync_inspect_entity',
            'entity_type',
        ]);
        $entity_id = $form_state->getValue([
            'cms_content_sync_inspect_entity',
            'entity_id',
        ]);

        $result = [];

        if ($entity_type && $entity_id) {
            $storage = $this->entityTypeManager->getStorage($entity_type);
            $entity = $storage->load($entity_id);
            if (!$entity) {
                $form_state->setError($form['cms_content_sync_inspect_entity']['entity_id'], 'This entity doesn\'t exist.');

                return $result;
            }
        }

        $ajax_response = new AjaxResponse();

        if ($entity) {
            $result = [
                '#type' => 'table',
                '#sticky' => true,
                '#header' => array_merge([
                    $this->t('Label'),
                    $this->t('Entity Type'),
                    $this->t('Bundle'),
                    $this->t('ID'),
                    $this->t('UUID'),
                    $this->t('Entity Status ID'),
                    $this->t('Flow'),
                    $this->t('Pool'),
                    $this->t('Flags'),
                    $this->t('Last push / edit date'),
                    $this->t('Last pull'),
                    $this->t('Latest log messages'),
                ]),
            ];
            $this->debugEntity($result, $entity);

            $ajax_response->addCommand(new HtmlCommand('.cms_content_sync-inspect-entity-output', $result));
            $serializer = \Drupal::service('serializer');
            $data = $serializer->serialize($entity, 'json', ['plugin_id' => 'entity']);

            $entity_data = '<b>Entity Data:</b><br>';
            $entity_data .= '<br><pre><code>'.$data.'</code></pre>';
            $ajax_response->addCommand(new HtmlCommand('.cms_content_sync-inspect-entity-data-output', $entity_data));
        }

        return $ajax_response;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $form['#tree'] = true;
        $settings = ContentSyncSettings::getInstance();

        $can_refresh_login = false;
        foreach (SyncCoreFactory::getAllSyncCores() as $core) {
            if ($core->featureEnabled(ISyncCore::FEATURE_REFRESH_AUTHENTICATION)) {
                $can_refresh_login = true;

                break;
            }
        }
        if ($can_refresh_login) {
            $form['cms_content_sync_login'] = [
                '#type' => 'submit',
                '#value' => $this->t('Login at Sync Core'),
            ];
        }

        // The extended entity export/import logging is stored within the key value table
        // since we do not want this stored within the Drupal configuration.
        $form['cms_content_sync_extended_entity_export_logging'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Extended Entity Export logging'),
            '#default_value' => $settings->getExtendedEntityExportLogging() ?? 0,
            '#description' => $this->t('When the "Extended Entity Export logging" is enabled, Content Sync is going to add a log entry to Watchdog
                                        showing all entity values processed by content sync after the <b><u>export</u></b>. This is helpful to debug outgoing entities.<br>
                                        <b>This will create many large log messages, so only use this for a short period of time and disable it immediately after your debugging session.</b>'),
        ];

        $form['cms_content_sync_extended_entity_import_logging'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Extended Entity Import logging'),
            '#default_value' => $settings->getExtendedEntityImportLogging() ?? 0,
            '#description' => $this->t('When the "Extended Entity Import logging" is enabled, Content Sync is going to add a log entry to Watchdog
                                        showing all entity values processed by content sync after the <b><u>import</u></b>. This is helpful to identify if an entity
                                        has changed after content sync has processed it.<br>
                                        <b>This will create many large log messages, so only use this for a short period of time and disable it immediately after your debugging session.</b>'),
        ];

        $form['cms_content_sync_inspect_entity'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Inspect entity'),
        ];

        $entity_types = $this->entityTypeManager->getDefinitions();
        $field_map = $this->entityFieldManager->getFieldMap();
        $entity_types_names = [];
        foreach ($entity_types as $type_key => $entity_type) {
            // This entity type doesn't contain any fields.
            if (!isset($field_map[$type_key])) {
                continue;
            }
            if ('cms_content_sync' == $entity_type->getProvider()) {
                continue;
            }

            $entity_types_names[$type_key] = $type_key;
        }

        $form['cms_content_sync_inspect_entity']['entity_type'] = [
            '#type' => 'select',
            '#title' => $this->t('Entity type'),
            '#options' => $entity_types_names,
            '#default_value' => 'node',
        ];
        $form['cms_content_sync_inspect_entity']['entity_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Entity ID'),
        ];
        $form['cms_content_sync_inspect_entity']['submit'] = [
            '#type' => 'button',
            '#value' => $this->t('Check'),
            '#ajax' => [
                'callback' => '::inspectEntity',
                'wrapper' => 'cms_content_sync-inspect-entity-output',
            ],
        ];
        $form['cms_content_sync_inspect_entity']['entity'] = [
            '#prefix' => '<div class="cms_content_sync-inspect-entity-output">',
            '#suffix' => '</div>',
        ];
        $form['cms_content_sync_inspect_entity']['entity_data'] = [
            '#prefix' => '<div class="cms_content_sync-inspect-entity-data-output">',
            '#suffix' => '</div>',
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        parent::validateForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        parent::submitForm($form, $form_state);

        $settings = ContentSyncSettings::getInstance();
        $settings->setExtendedEntityExportLogging($form_state->getValue('cms_content_sync_extended_entity_export_logging'));
        $settings->setExtendedEntityImportLogging($form_state->getValue('cms_content_sync_extended_entity_import_logging'));

        if ($form_state->getValue('op') == t('Login at Sync Core')) {
            $messenger = \Drupal::messenger();

            foreach (SyncCoreFactory::getAllSyncCores() as $host => $core) {
                if (!$core->featureEnabled(ISyncCore::FEATURE_REFRESH_AUTHENTICATION)) {
                    continue;
                }
                if ($core->getSyndicationService()->refreshAuthentication()) {
                    $messenger->addStatus('SUCCESS login from Sync Core at '.$host);
                } else {
                    $messenger->addError('FAILED to login from Sync Core at '.$host);
                }
            }
        }
    }

    /**
     * @param array                               $result
     *                                                             The table render array
     * @param \Drupal\Core\Entity\EntityInterface $entity
     * @param array                               $parent_entities
     *                                                             Entities that have already been processed up the ladder
     */
    protected function debugEntity(&$result, $entity, $parent_entities = [])
    {
        $label = str_repeat('+', count($parent_entities)).' ';
        /*foreach($parent_entities as $parent_entity) {
        $label .= $parent_entity->label().' => ';
        }*/
        $label .= $entity->label();

        $moduleHandler = \Drupal::service('module_handler');
        $dblog_enabled = $moduleHandler->moduleExists('dblog');
        if ($dblog_enabled) {
            $connection = \Drupal::database();
            $log_levels = RfcLogLevel::getLevels();
        } else {
            \Drupal::messenger()
                ->addMessage('dblog is disabled, so no log messages will be displayed.');
        }

        $children = [];

        $infos = EntityStatus::getInfosForEntity($entity->getEntityTypeId(), $entity->uuid());
        if (!count($infos)) {
            return;
        }

        foreach ($infos as $index => $info) {
            $current_row = [];
            if (0 == $index) {
                $current_row['label'] = ['#markup' => $label];
                $current_row['entity_type'] = ['#markup' => $entity->getEntityTypeId()];
                $current_row['bundle'] = ['#markup' => $entity->bundle()];
                $current_row['id'] = ['#markup' => $entity->id()];
                $current_row['uuid'] = ['#markup' => $entity->uuid()];
            } else {
                $current_row['label'] = ['#markup' => ''];
                $current_row['entity_type'] = ['#markup' => ''];
                $current_row['bundle'] = ['#markup' => ''];
                $current_row['id'] = ['#markup' => ''];
                $current_row['uuid'] = ['#markup' => ''];
            }

            $flow = $info->getFlow();
            $pool = $info->getPool();

            $current_row['entity_status_id'] = ['#markup' => $info->id->value];
            $current_row['flow'] = ['#markup' => $flow ? $flow->label() : $info->get('flow')->value];
            $current_row['pool'] = ['#markup' => $pool ? $pool->label() : $info->get('pool')->value];

            $current_row['flags'] = ['#theme' => 'item_list'];
            if ($info->isSourceEntity()) {
                $current_row['flags']['#items'][]['#markup'] = 'Source';
            }
            if ($info->isManualPushEnabled()) {
                $current_row['flags']['#items'][]['#markup'] = 'Pushing enabled';
            }
            if ($info->didUserEnablePush()) {
                $current_row['flags']['#items'][]['#markup'] = 'Pushed by user';
            }
            if ($info->isPushedAsDependency()) {
                $current_row['flags']['#items'][]['#markup'] = 'Pushed as dependency';
            }
            if ($info->isOverriddenLocally()) {
                $current_row['flags']['#items'][]['#markup'] = 'Overridden locally';
            }

            $timestamp = $info->getLastPush();
            $current_row['last_export'] = [
                '#markup' => $timestamp ? \Drupal::service('date.formatter')
                    ->format($timestamp, 'long') : 'NEVER',
            ];

            $timestamp = $info->getLastPull();
            $current_row['last_import'] = [
                '#markup' => $timestamp ? \Drupal::service('date.formatter')
                    ->format($timestamp, 'long') : 'NEVER',
            ];

            $current_row['log_messages'] = ['#theme' => 'item_list', '#items' => []];
            $query = $connection
                ->select('watchdog', 'w')
                ->fields('w', ['timestamp', 'severity', 'message', 'variables'])
                ->orderBy('timestamp', 'DESC')
                ->range(0, 3)
                ->condition('type', 'cms_content_sync')
                ->condition('variables', '%'.$connection->escapeLike($entity->uuid()).'%', 'LIKE');
            $query = $query->execute();
            $rows = $query->fetchAll();
            foreach ($rows as $res) {
                $message =
          '<strong>'.
          $log_levels[$res->severity].
          '</strong> <em>'.
          \Drupal::service('date.formatter')->format($res->timestamp, 'long').
          '</em> '.
          $this->formatMessage($res)->render();

                $current_row['log_messages']['#items'][]['#markup'] = $message;
            }

            $intent = new PushIntent($info->getFlow(), $info->getPool(), PushIntent::PUSH_FORCED, SyncIntent::ACTION_CREATE, $entity);
            $intent->execute(true);
            $serialized = $intent->getOperation()->getData();
            foreach ($serialized->getEmbeddedEntityData as $child) {
                $id = $child[Entity::ENTITY_TYPE_KEY].$child[Entity::UUID_KEY];
                if (isset($children[$id])) {
                    continue;
                }
                $children[$id] = $child;
            }

            $result[] = $current_row;
        }

        $result[0]['label']['#attributes']['rowspan'] = $index + 1;
        $result[0]['entity_type']['#attributes']['rowspan'] = $index + 1;
        $result[0]['bundle']['#attributes']['rowspan'] = $index + 1;
        $result[0]['id']['#attributes']['rowspan'] = $index + 1;
        $result[0]['uuid']['#attributes']['rowspan'] = $index + 1;

        /** @var \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository */
        $entity_repository = \Drupal::service('entity.repository');

        $parent_entities[] = $entity;
        foreach ($children as $child) {
            $child_entity = $entity_repository->loadEntityByUuid($child[Entity::ENTITY_TYPE_KEY], $child[Entity::UUID_KEY]);
            $this->debugEntity($result, $child_entity, $parent_entities);
        }
    }

    /**
     * Formats a database log message.
     *
     * @param object $row
     *                    The record from the watchdog table. The object properties are: wid, uid,
     *                    severity, type, timestamp, message, variables, link, name.
     *
     * @return false|string|TranslatableMarkup
     *                                         The formatted log message or FALSE if the message or variables properties
     *                                         are not set
     */
    protected function formatMessage($row)
    {
        // Check for required properties.
        if (isset($row->message, $row->variables)) {
            $variables = @unserialize($row->variables);
            // Messages without variables or user specified text.
            if (null === $variables) {
                $message = Xss::filterAdmin($row->message);
            } elseif (!is_array($variables)) {
                $message = $this->t('Log data is corrupted and cannot be unserialized: @message', ['@message' => Xss::filterAdmin($row->message)]);
            }
            // Message to translate with injected variables.
            else {
                $message = $this->t(Xss::filterAdmin($row->message), $variables);
            }
        } else {
            $message = false;
        }

        return $message;
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames()
    {
        return [
            'cms_content_sync.debug',
        ];
    }
}
