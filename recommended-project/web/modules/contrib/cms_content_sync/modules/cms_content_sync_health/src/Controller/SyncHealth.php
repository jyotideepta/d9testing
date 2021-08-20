<?php

namespace Drupal\cms_content_sync_health\Controller;

use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\PullIntent;
use EdgeBox\SyncCore\Interfaces\IReportingService;
use EdgeBox\SyncCore\V1\Helper;
use Drupal\cms_content_sync\SyncCoreInterface\DrupalApplication;
use Drupal\cms_content_sync\SyncCoreInterface\SyncCoreFactory;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Controller\ControllerBase;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\update\UpdateFetcher;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;
use function t;

/**
 * Provides a listing of Flow.
 */
class SyncHealth extends ControllerBase {

  /**
   * The Drupal core database connection.
   *
   * @var \Drupal\Core\Database\Database
   */
  protected $database;

  /**
   * The Drupal Core module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * The Drupal Core config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * The Drupal Core Date Formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The Drupal Core messenger interface.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The Drupal Core Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Constructs a \Drupal\cms_content_sync_health\Controller\SyncHealth object.
   *
   * @param \Drupal\Core\Database\Connection $database
   * @param \Drupal\Core\Extension\ModuleHandler $moduleHandler
   * @param \Drupal\Core\Config\ConfigFactory $configFactory
   * @param \Drupal\Core\Datetime\DateFormatter $dateFormatter
   * @param \GuzzleHttp\Client $httpClient
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   */
  public function __construct(Connection $database, ModuleHandler $moduleHandler, ConfigFactory $configFactory, DateFormatter $dateFormatter, Client $httpClient, MessengerInterface $messenger, EntityTypeManager $entityTypeManager) {
    $this->database = $database;
    $this->moduleHandler = $moduleHandler;
    $this->configFactory = $configFactory;
    $this->dateFormatter = $dateFormatter;
    $this->httpClient = $httpClient;
    $this->messenger = $messenger;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('module_handler'),
      $container->get('config.factory'),
      $container->get('date.formatter'),
      $container->get('http_client'),
      $container->get('messenger'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Formats a database log message.
   *
   * @param object $row
   *   The record from the watchdog table. The object properties are: wid, uid,
   *   severity, type, timestamp, message, variables, link, name.
   *
   * @return string|TranslatableMarkup|false
   *   The formatted log message or FALSE if the message or variables properties
   *   are not set.
   */
  protected static function formatMessage($row) {
    // Check for required properties.
    if (isset($row->message, $row->variables)) {
      $variables = @unserialize($row->variables);
      // Messages without variables or user specified text.
      if ($variables === NULL) {
        $message = Xss::filterAdmin($row->message);
      }
      elseif (!is_array($variables)) {
        $message = t('Log data is corrupted and cannot be unserialized: @message', ['@message' => Xss::filterAdmin($row->message)]);
      }
      // Message to translate with injected variables.
      else {
        $message = t(Xss::filterAdmin($row->message), $variables);
      }
    }
    else {
      $message = FALSE;
    }
    return $message;
  }

  /**
   * Count status entities with the given flag.
   *
   * @param int $flag
   *   See EntityStatus::FLAG_*.
   * @param array $details
   *   Search the 'data' column to contain the given $value and save it in the result array at $key.
   *
   * @return array The counts, always having 'total'=>... and optionally the counts given by $details.
   */
  protected function countStatusEntitiesWithFlag($flag, $details = []) {
    $result['total'] = $this->database->select('cms_content_sync_entity_status')
      ->where('flags&:flag=:flag', [':flag' => $flag])
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($result['total']) {
      foreach ($details as $name => $search) {
        $search = '%' . $this->database->escapeLike($search) . '%';
        $result[$name] = $this->database->select('cms_content_sync_entity_status')
          ->where('flags&:flag=:flag', [':flag' => $flag])
          ->condition('data', $search, 'LIKE')
          ->countQuery()
          ->execute()
          ->fetchField();
      }
    }

    return $result;
  }

  /**
   *
   */
  protected function getLocalLogMessages($levels, $count = 10) {
    $result = [];

    $connection = $this->database;

    $query = $connection
      ->select('watchdog', 'w')
      ->fields('w', ['timestamp', 'severity', 'message', 'variables'])
      ->orderBy('timestamp', 'DESC')
      ->range(0, $count)
      ->condition('type', 'cms_content_sync')
      ->condition('severity', $levels, 'IN');
    $query = $query->execute();
    $rows = $query->fetchAll();
    foreach ($rows as $res) {
      $message =
        '<em>' .
        $this->dateFormatter->format($res->timestamp, 'long') .
        '</em> ' .
        self::formatMessage($res)->render();

      $result[] = $message;
    }

    $result = Helper::obfuscateCredentials($result);

    return $result;
  }

  /**
   * Filter the given messages to only display those related to this site.
   *
   * @param array[] $messages
   *
   * @return array[]
   */
  protected function filterSyncCoreLogMessages($messages) {
    $result = [];

    $allowed_prefixes = [];
    foreach (Pool::getAll() as $pool) {
      $allowed_prefixes[] = 'drupal-' . $pool->id() . '-' . DrupalApplication::get()->getSiteMachineName() . '-';
    }

    foreach ($messages as $msg) {
      if (!isset($msg['connection_id'])) {
        continue;
      }

      $keep = FALSE;

      foreach ($allowed_prefixes as $allowed) {
        if (substr($msg['connection_id'], 0, strlen($allowed)) == $allowed) {
          $keep = TRUE;
          break;
        }
      }

      if ($keep) {
        $result[] = $msg;
      }
    }

    return array_slice($result, -20);
  }

  /**
   * Render the overview page.
   *
   * @return array
   */
  public function overview() {
    $sync_cores = [];
    foreach (SyncCoreFactory::getAllSyncCores() as $host => $core) {
      $status = $core->getReportingService()->getStatus();
      $reporting = $core->getReportingService();
      $status['error_log'] = $this->filterSyncCoreLogMessages($reporting->getLog(IReportingService::LOG_LEVEL_ERROR));
      $status['warning_log'] = $this->filterSyncCoreLogMessages($reporting->getLog(IReportingService::LOG_LEVEL_WARNING));
      $sync_cores[$host] = $status;
    }

    $module_info = \Drupal::service('extension.list.module')->getExtensionInfo('cms_content_sync');
    $moduleHandler = $this->moduleHandler;
    if ($moduleHandler->moduleExists('update')) {
      $updates = new UpdateFetcher($this->configFactory, $this->httpClient);
      $available = $updates->fetchProjectData([
        'name' => 'cms_content_sync',
        'info' => $module_info,
        'includes' => [],
        'project_type' => 'module',
        'project_status' => TRUE,
      ]);
      preg_match_all('@<version>\s*8.x-([0-9]+)\.([0-9]+)\s*</version>@i', $available, $versions, PREG_SET_ORDER);
      $newest_major = 0;
      $newest_minor = 0;
      foreach ($versions as $version) {
        if ($version[1] > $newest_major) {
          $newest_major = $version[1];
          $newest_minor = $version[2];
        }
        elseif ($version[1] == $newest_major && $version[2] > $newest_minor) {
          $newest_minor = $version[2];
        }
      }
      $newest_version = $newest_major . '.' . $newest_minor;
    }
    else {
      $newest_version = NULL;
    }

    if (isset($module_info['version'])) {
      $module_version = $module_info['version'];
      $module_version = preg_replace('@^\d\.x-(.*)$@', '$1', $module_version);
      if ($module_version != $newest_version) {
        if ($newest_version) {
          $this->messenger->addMessage(t('There\'s an update available! The newest module version is @newest, yours is @current.', ['@newest' => $newest_version, '@current' => $module_version]));
        }
        else {
          $this->messenger->addMessage(t('Please enable the "update" module to see if you\'re running the latest Content Sync version.'));
        }
      }
    }
    else {
      $module_version = NULL;
      if ($newest_version) {
        $this->messenger->addWarning(t('You\'re running a dev release. The newest module version is @newest.', ['@newest' => $newest_version]));
      }
    }

    $push_failures_hard = $this->countStatusEntitiesWithFlag(EntityStatus::FLAG_PUSH_FAILED);

    $push_failures_soft = $this->countStatusEntitiesWithFlag(EntityStatus::FLAG_PUSH_FAILED_SOFT);

    $pull_failures_hard = $this->countStatusEntitiesWithFlag(EntityStatus::FLAG_PULL_FAILED);

    $pull_failures_soft = $this->countStatusEntitiesWithFlag(EntityStatus::FLAG_PULL_FAILED_SOFT);

    $version_differences['local'] = $this->getLocalVersionDifferences();

    $moduleHandler = $this->moduleHandler;
    $dblog_enabled = $moduleHandler->moduleExists('dblog');
    if ($dblog_enabled) {
      $site_log_disabled = FALSE;
      $error_log = $this->getLocalLogMessages([
        RfcLogLevel::EMERGENCY,
        RfcLogLevel::ALERT,
        RfcLogLevel::CRITICAL,
        RfcLogLevel::ERROR,
      ]);
      $warning_log = $this->getLocalLogMessages([
        RfcLogLevel::WARNING,
      ]);
    }
    else {
      $site_log_disabled = TRUE;
      $error_log = NULL;
      $warning_log = NULL;
    }

    return [
      '#theme' => 'cms_content_sync_sync_health_overview',
      '#sync_cores' => $sync_cores,
      '#module_version' => $module_version,
      '#newest_version' => $newest_version,
      '#push_failures_hard' => $push_failures_hard,
      '#push_failures_soft' => $push_failures_soft,
      '#pull_failures_hard' => $pull_failures_hard,
      '#pull_failures_soft' => $pull_failures_soft,
      '#version_differences' => $version_differences,
      '#error_log' => $error_log,
      '#warning_log' => $warning_log,
      '#site_log_disabled' => $site_log_disabled,
    ];
  }

  /**
   *
   */
  protected function countStaleEntities() {
    $checked = [];
    $count = 0;

    foreach (Flow::getAll() as $flow) {
      foreach ($flow->getEntityTypeConfig(NULL, NULL, TRUE) as $id => $config) {
        if (in_array($id, $checked)) {
          continue;
        }

        if ($config['export'] != PushIntent::PUSH_AUTOMATICALLY) {
          continue;
        }

        if (!in_array(Pool::POOL_USAGE_FORCE, array_values($config['export_pools']))) {
          continue;
        }

        $checked[] = $id;

        $type_name = $config['entity_type_name'];
        $bundle_name = $config['bundle_name'];

        /**
         * @var \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
         */
        $entityTypeManager = $this->entityTypeManager;
        $type = $entityTypeManager->getDefinition($type_name);

        $query = $this->database->select($type->getBaseTable(), 'e');

        $query
          ->leftJoin('cms_content_sync_entity_status', 's', 'e.uuid=s.entity_uuid AND s.entity_type=:type', [':type' => $type_name]);

        $query = $query
          ->isNull('s.id');

        // Some entity types don't store their bundle information in their table if they don't actually have multiple
        // bundles.
        if (!in_array($type_name, ['bibcite_contributor', 'bibcite_keyword'])) {
          $query = $query
            ->condition('e.' . $type->getKey('bundle'), $bundle_name);
        }

        $result = $query
          ->countQuery()
          ->execute();

        $count += (int) $result
          ->fetchField();
      }
    }

    return $count;
  }

  /**
   *
   */
  protected function getLocalVersionDifferences() {
    $result = [];

    foreach (Flow::getAll() as $flow) {
      foreach ($flow->getEntityTypeConfig(NULL, NULL, TRUE) as $id => $config) {
        $type_name = $config['entity_type_name'];
        $bundle_name = $config['bundle_name'];
        $version = $config['version'];

        $current = Flow::getEntityTypeVersion($type_name, $bundle_name);

        if ($version == $current) {
          continue;
        }

        $result[] = $flow->label() . ' uses entity type  ' . $type_name . '.' . $bundle_name . ' with version ' . $version . '. Current version is ' . $current . '. Please update the Flow.';
      }
    }

    return $result;
  }

  /**
   *
   */
  protected function countEntitiesWithChangedVersionForPush() {
    $checked = [];
    $versions = [];
    $types = [];

    foreach (Flow::getAll() as $flow) {
      foreach ($flow->getEntityTypeConfig(NULL, NULL, TRUE) as $id => $config) {
        if (in_array($id, $checked)) {
          continue;
        }

        $checked[] = $id;

        $type_name = $config['entity_type_name'];
        $version = $config['version'];
        if (!in_array($type_name, $types)) {
          $types[] = $type_name;
        }
        $versions[] = $version;
      }
    }

    $count = $this->database->select('cms_content_sync_entity_status')
      ->condition('entity_type', $types, 'IN')
      ->condition('entity_type_version', $versions, 'NOT IN')
      ->where('flags&:flag=:flag', [':flag' => EntityStatus::FLAG_IS_SOURCE_ENTITY])
      ->countQuery()
      ->execute()
      ->fetchField();

    return $count;
  }

  /**
   *
   */
  protected function countEntitiesWaitingForPush() {
    return 0;
  }

  /**
   * Render the overview page.
   *
   * @return array
   */
  public function pushing() {
    $push_failures_hard = $this->countStatusEntitiesWithFlag(EntityStatus::FLAG_PUSH_FAILED, [
      'request_failed' => PushIntent::PUSH_FAILED_REQUEST_FAILED,
      'invalid_status_code' => PushIntent::PUSH_FAILED_REQUEST_INVALID_STATUS_CODE,
      'dependency_push_failed' => PushIntent::PUSH_FAILED_DEPENDENCY_PUSH_FAILED,
      'internal_error' => PushIntent::PUSH_FAILED_INTERNAL_ERROR,
    ]);

    $push_failures_soft = $this->countStatusEntitiesWithFlag(EntityStatus::FLAG_PUSH_FAILED_SOFT, [
      'handler_denied' => PushIntent::PUSH_FAILED_HANDLER_DENIED,
      'unchanged' => PushIntent::PUSH_FAILED_UNCHANGED,
    ]);

    $pending = [
      'stale_entities' => $this->countStaleEntities(),
      'version_changed' => $this->countEntitiesWithChangedVersionForPush(),
      'manual_push' => $this->countEntitiesWaitingForPush(),
    ];

    return [
      '#theme' => 'cms_content_sync_sync_health_push',
      '#push_failures_hard' => $push_failures_hard,
      '#push_failures_soft' => $push_failures_soft,
      '#pending' => $pending,
    ];
  }

  /**
   * Render the overview page.
   *
   * @return array
   */
  public function pulling() {
    $pull_failures_hard = $this->countStatusEntitiesWithFlag(EntityStatus::FLAG_PULL_FAILED, [
      'different_version' => PullIntent::PULL_FAILED_DIFFERENT_VERSION,
      'sync_error' => PullIntent::PULL_FAILED_CONTENT_SYNC_ERROR,
      'internal_error' => PullIntent::PULL_FAILED_INTERNAL_ERROR,
    ]);

    $pull_failures_soft = $this->countStatusEntitiesWithFlag(EntityStatus::FLAG_PULL_FAILED_SOFT, [
      'handler_denied' => PullIntent::PULL_FAILED_HANDLER_DENIED,
      'no_flow' => PullIntent::PULL_FAILED_NO_FLOW,
      'unknown_pool' => PullIntent::PULL_FAILED_UNKNOWN_POOL,
    ]);

    return [
      '#theme' => 'cms_content_sync_sync_health_pull',
      '#pull_failures_hard' => $pull_failures_hard,
      '#pull_failures_soft' => $pull_failures_soft,
    ];
  }

}
