<?php

namespace Drupal\cms_content_sync_migrate_acquia_content_hub\Form;

use Drupal\acquia_contenthub\EntityManager;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\cms_content_sync\Form\PoolForm;
use EdgeBox\SyncCore\Interfaces\IApplicationInterface;
use Drupal\cms_content_sync_migrate_acquia_content_hub\CreateStatusEntities;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Content Sync advanced debug form.
 */
class MigrationBase extends FormBase {

  /**
   * The acquia content hub entity manager.
   *
   * @var \Drupal\acquia_contenthub\EntityManager
   */
  protected $acquiaEntityManager;

  /**
   * The entity type bundle info manager interface.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * The field type plugin manager.
   *
   * @var \Drupal\Core\Field\FieldTypePluginManagerInterface
   */
  protected $fieldTypePluginManager;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Drupal module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * The core entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   *
   */
  public static function getTermsFromFilter($tags) {
    if (empty($tags)) {
      return [];
    }

    $uuids = explode(',', $tags);
    $tags = [];
    foreach ($uuids as $uuid) {
      $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['uuid' => $uuid]);
      if (!count($terms)) {
        \Drupal::messenger()->addMessage('Term ' . $uuid . ' could not be loaded and has been ignored.', 'warning');
        continue;
      }
      $term = reset($terms);
      $tags[] = $term;
    }

    return $tags;
  }

  /**
   * Create the pools based on the user selected terms.
   *
   * @param $pools
   * @param $backend_url
   * @param $authentication_type
   */
  public static function createPools($pools, $backend_url, $authentication_type) {
    foreach ($pools as $machine_name => $name) {
      Pool::createPool($name, $machine_name, $backend_url, $authentication_type);
    }

    \Drupal::messenger()->addMessage('Content Sync Pools have been created.');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'cms_content_sync_migrate_acquia_content_hub.migration_base';
  }

  /**
   * Constructs a new FieldStorageAddForm object.
   *
   * @param \Drupal\acquia_contenthub\EntityManager $acquia_entity_manager
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   * @param \Drupal\Core\Field\FieldTypePluginManagerInterface $field_type_plugin_manager
   *   The field type plugin manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   *
   * @param \Drupal\Core\Extension\ModuleHandler $module_handler
   *
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   *
   * @internal param \Drupal\Core\Entity\EntityManagerInterface $entity_manager The entity manager.*   The entity manager.
   */
  public function __construct(EntityManager $acquia_entity_manager,
                              EntityTypeBundleInfoInterface $entity_type_bundle_info,
                              FieldTypePluginManagerInterface $field_type_plugin_manager,
                              ConfigFactoryInterface $config_factory,
                              ModuleHandler $module_handler,
                              EntityTypeManager $entity_type_manager) {
    $this->acquiaEntityManager = $acquia_entity_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->fieldTypePluginManager = $field_type_plugin_manager;
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('acquia_contenthub.entity_manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('plugin.manager.field.field_type'),
      $container->get('config.factory'),
      $container->get('module_handler'),
      $container->get('entity_type.manager')
    );
  }

  /**
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['backend_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Sync Core URL'),
      '#required' => TRUE,
    ];

    $auth_options = [
      IApplicationInterface::AUTHENTICATION_TYPE_COOKIE => $this->t("Standard (Cookie)"),
    ];
    if ($this->moduleHandler->moduleExists('basic_auth')) {
      $auth_options[IApplicationInterface::AUTHENTICATION_TYPE_BASIC_AUTH] = $this->t("Basic Auth");
    }

    $form['authentication_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Authentication'),
      '#description' => $this->t(PoolForm::AUTHENTICATION_TYPE_DESCRIPTION),
      '#options' => $auth_options,
      '#required' => TRUE,
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create configuration'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  const DEFAULT_POOL_MACHINE_NAME = 'content';
  const DEFAULT_POOL = [
    self::DEFAULT_POOL_MACHINE_NAME => 'Content',
  ];

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $content_hub_filter = '';
    if (isset($this->content_hub_filter)) {
      $content_hub_filter = $this->content_hub_filter;
    }

    if (!isset($this->migrationType)) {
      return;
    }

    // Create pools.
    MigratePush::createPools(self::DEFAULT_POOL, $form_state->getValue('backend_url'), $form_state->getValue('authentication_type'));

    if ($this->migrationType == 'push') {
      // Create flow.
      $flow = MigratePush::createFlow(MigrationBase::DEFAULT_POOL_MACHINE_NAME, $form_state->getValue('node_push_behavior'), $form_state->getValue('pull_updates_behavior'));
    }
    else {
      // Create flow.
      $flow = MigratePull::createFlow(MigrationBase::DEFAULT_POOL_MACHINE_NAME, $form_state->getValue('node_push_behavior'), $form_state->getValue('pull_updates_behavior'), $content_hub_filter);
    }

    // Create status entities.
    $create_status_entities = new CreateStatusEntities();
    $operations = $create_status_entities->prepare($flow['flow_id'], $flow['flow_configuration'], MigrationBase::DEFAULT_POOL_MACHINE_NAME, $flow['type'], $content_hub_filter ? $content_hub_filter->tags : '');

    $batch = [
      'title' => t('Creating status entities'),
      'operations' => $operations,
    ];
    batch_set($batch);

    // Redirect user to flow form.
    $route_paramenters = [
      'cms_content_sync_flow' => $flow['flow_id'],
    ];

    $form_state->setRedirect('entity.cms_content_sync_flow.edit_form', $route_paramenters);
  }

}
