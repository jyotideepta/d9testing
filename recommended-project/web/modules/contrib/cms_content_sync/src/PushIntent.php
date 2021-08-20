<?php

namespace Drupal\cms_content_sync;

use Drupal\cms_content_sync\Controller\ContentSyncSettings;
use Drupal\cms_content_sync\Controller\Migration;
use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\cms_content_sync\Event\AfterEntityPush;
use Drupal\cms_content_sync\Exception\SyncException;
use Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\crop\Entity\Crop;
use EdgeBox\SyncCore\Exception\SyncCoreException;
use function t;

/**
 * Class PushIntent.
 */
class PushIntent extends SyncIntent
{
    /**
     * @var string PUSH_DISABLED
     *             Disable pushing completely for this entity type, unless forced.
     *             - used as a configuration option
     *             - not used as $action
     */
    public const PUSH_DISABLED = 'disabled';
    /**
     * @var string PUSH_AUTOMATICALLY
     *             Automatically push all entities of this entity type.
     *             - used as a configuration option
     *             - used as $action
     */
    public const PUSH_AUTOMATICALLY = 'automatically';
    /**
     * @var string PUSH_MANUALLY
     *             Push only some of these entities, chosen manually.
     *             - used as a configuration option
     *             - used as $action
     */
    public const PUSH_MANUALLY = 'manually';
    /**
     * @var string PUSH_AS_DEPENDENCY
     *             Push only some of these entities, pushed if other pushed entities
     *             use it.
     *             - used as a configuration option
     *             - used as $action
     */
    public const PUSH_AS_DEPENDENCY = 'dependency';
    /**
     * @var string PUSH_FORCED
     *             Force the entity to be pushed (as long as a handler is also selected).
     *             Can be used programmatically for custom workflows.
     *             - not used as a configuration option
     *             - used as $action
     */
    public const PUSH_FORCED = 'forced';
    /**
     * @var string PUSH_ANY
     *             Only used as a filter to check if the Flow pushes this entity in any
     *             way.
     *             - not used as a configuration option
     *             - not used as $action
     *             - so only used to query against Flows that have *any* push setting for a given entity (type).
     */
    public const PUSH_ANY = 'any';

    /**
     * @var string PUSH_FAILED_REQUEST_FAILED
     *             The request to the Sync Core failed completely
     */
    public const PUSH_FAILED_REQUEST_FAILED = 'export_failed_request_failed';
    /**
     * @var string PUSH_FAILED_REQUEST_INVALID_STATUS_CODE
     *             The Sync Core returned a non-2xx status code
     */
    public const PUSH_FAILED_REQUEST_INVALID_STATUS_CODE = 'export_failed_invalid_status_code';
    /**
     * @var string PUSH_FAILED_DEPENDENCY_PUSH_FAILED
     *             The entity wasn't pushed because when pushing a dependency, an error was thrown
     */
    public const PUSH_FAILED_DEPENDENCY_PUSH_FAILED = 'export_failed_dependency_export_failed';
    /**
     * @var string PUSH_FAILED_INTERNAL_ERROR
     *             The entity wasn't pushed because when serializing it, an error was thrown
     */
    public const PUSH_FAILED_INTERNAL_ERROR = 'export_failed_internal_error';
    /**
     * @var string PUSH_FAILED_HANDLER_DENIED
     *             Soft fail: The push failed because the handler returned FALSE when executing the push
     */
    public const PUSH_FAILED_HANDLER_DENIED = 'export_failed_handler_denied';
    /**
     * @var string PUSH_FAILED_UNCHANGED
     *             Soft fail: The entity wasn't pushed because it didn't change since the last push
     */
    public const PUSH_FAILED_UNCHANGED = 'export_failed_unchanged';

    /**
     * @var string NO_PUSH_REASON__JUST_PULLED The entity has been pulled
     *             during this very request, so it can't be pushed again immediately
     */
    public const NO_PUSH_REASON__JUST_PULLED = 'JUST_IMPORTED';

    /**
     * @var string NO_PUSH_REASON__NEVER_PUSHED The entity has never been
     *             pushed before, so pushing the deletion doesn't make sense (it will
     *             not even exist remotely yet)
     */
    public const NO_PUSH_REASON__NEVER_PUSHED = 'NEVER_EXPORTED';

    /**
     * @var string NO_PUSH_REASON__UNCHANGED The entity hasn't changed, so the
     *             push would not do anything
     */
    public const NO_PUSH_REASON__UNCHANGED = 'UNCHANGED';

    /**
     * @var string NO_PUSH_REASON__HANDLER_IGNORES The handler for the entity
     *             refused to push this entity. These are usually handler specific
     *             configurations like "Don't push unpublished content" for nodes.
     */
    public const NO_PUSH_REASON__HANDLER_IGNORES = 'HANDLER_IGNORES';

    /**
     * @var string NO_PUSH_REASON__NO_POOL No pool was assigned, so there's no push to take place
     */
    public const NO_PUSH_REASON__NO_POOL = 'NO_POOL';

    /**
     * @var \EdgeBox\SyncCore\Interfaces\Syndication\IPushSingle
     */
    protected $operation;

    protected $isQuickEdited = false;

    protected $entityVersionHash;

    /**
     * @var array
     *            A list of all pushed entities to make sure entities aren't pushed
     *            multiple times during the same request in the format
     *            [$action][$entity_type][$bundle][$uuid] => TRUE
     */
    protected static $pushed = [];

    /**
     * @var array
     *            pushed. Can be queried via self::getNoPushReason($entity). Structure:
     *            [ entity_type_id:string ][ entity_uuid:string ] => string|Exception
     */
    protected static $noPushReasons = [];

    /**
     * @var PushIntent[]
     */
    protected $embeddedPushIntents = [];

    /**
     * PushIntent constructor.
     *
     * @param $reason
     * @param $action
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Exception
     */
    public function __construct(Flow $flow, Pool $pool, $reason, $action, EntityInterface $entity)
    {
        parent::__construct($flow, $pool, $reason, $action, $entity->getEntityTypeId(), $entity->bundle(), $entity->uuid(), $entity instanceof ConfigEntityInterface ? $entity->id() : null);

        if (!$this->entity_status->getLastPush()) {
            if (!EntityStatus::getLastPullForEntity($entity) && !PullIntent::entityHasBeenPulledFromRemoteSite($entity->getEntityTypeId(), $entity->uuid())) {
                $this->entity_status->isSourceEntity(true);
            }
        }

        $this->entity = $entity;

        $moduleHandler = \Drupal::service('module_handler');
        $quickedit_enabled = $moduleHandler->moduleExists('quickedit');
        if ($quickedit_enabled && !empty(\Drupal::service('tempstore.private')->get('quickedit')->get($entity->uuid()))) {
            $this->isQuickEdited = true;
        }

        $type_config = $flow->getEntityTypeConfig($entity->getEntityTypeId(), $entity->bundle());

        $this->operation = $this->pool
            ->getClient()
            ->getSyndicationService()
            ->pushSingle(
                $this->flow->id,
                $entity->getEntityTypeId(),
                $entity->bundle(),
                $type_config['version'],
                $entity->language()->getId(),
                $entity->uuid(),
                EntityHandlerPluginManager::isEntityTypeConfiguration($entity->getEntityType()) ? $entity->id() : null
            )
            ->toPool($this->pool->id)
            ->asDependency(PushIntent::PUSH_AS_DEPENDENCY == $this->flow->getEntityTypeConfig($entity->getEntityTypeId(), $entity->bundle())['export']);
    }

    /**
     * Get the correct synchronization for a specific action on a given entity.
     *
     * @param string|string[] $reason
     * @param string          $action
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     *
     * @return \Drupal\cms_content_sync\Entity\Flow[]
     */
    public static function getFlowsForEntity(EntityInterface $entity, $reason, $action = SyncIntent::ACTION_CREATE)
    {
        $flows = Flow::getAll();

        $result = [];

        foreach ($flows as $flow) {
            if ($flow->canPushEntity($entity, $reason, $action)) {
                $result[] = $flow;
            }
        }

        return $result;
    }

    /**
     * Serialize the given entity using the entity push and field push
     * handlers.
     *
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     *
     * @return bool
     *              Whether or not the serialized entity could be created
     */
    public function serialize()
    {
        $config = $this->flow->getEntityTypeConfig($this->entityType, $this->bundle);
        $handler = $this->flow->getEntityTypeHandler($config);

        return $handler->push($this);
    }

    /**
     * @return \EdgeBox\SyncCore\Interfaces\Syndication\IPushSingle
     */
    public function getOperation()
    {
        return $this->operation;
    }

    /**
     * Push the given entity.
     *
     * @param bool $return_only
     *
     * @throws \Drupal\Core\Entity\EntityStorageException
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     *
     * @return bool|PushIntent TRUE|FALSE if the entity is pushed via REST.
     *                         NULL|PushIntent if $return_only is set to TRUE.
     */
    public function execute($return_only = false)
    {
        $action = $this->getAction();
        $reason = $this->getReason();
        $entity = $this->getEntity();

        /**
         * @var array $deletedTranslations
         *            The translations that have been deleted. Important to notice when
         *            updates must be performed (see ::ACTION_DELETE_TRANSLATION).
         */
        static $deletedTranslations = [];

        if (SyncIntent::ACTION_DELETE_TRANSLATION == $action) {
            $deletedTranslations[$entity->getEntityTypeId()][$entity->uuid()] = true;

            return false;
        }

        if ($entity instanceof TranslatableInterface) {
            $entity = $entity->getUntranslated();
            $this->entity = $entity;
        }

        // If this very request was sent to delete/create this entity, ignore the
        // push as the result of this request will already tell Sync Core it has
        // been deleted. Otherwise Sync Core will return a reasonable 404 for
        // deletions.
        if (PullIntent::entityHasBeenPulledFromRemoteSite($entity->getEntityTypeId(), $entity->uuid())) {
            self::$noPushReasons[$entity->getEntityTypeId()][$entity->uuid()] = self::NO_PUSH_REASON__JUST_PULLED;

            return false;
        }

        $entity_type = $entity->getEntityTypeId();
        $entity_bundle = $entity->bundle();
        $entity_uuid = $entity->uuid();

        $pushed = $this->entity_status->getLastPush();

        if ($pushed) {
            if (SyncIntent::ACTION_CREATE == $action) {
                $action = SyncIntent::ACTION_UPDATE;
            }
        } else {
            if (SyncIntent::ACTION_UPDATE == $action) {
                $action = SyncIntent::ACTION_CREATE;
            }
            // If the entity was deleted but has never been pushed before,
            // pushing the deletion action doesn't make sense as it doesn't even
            // exist remotely.
            elseif (SyncIntent::ACTION_DELETE == $action) {
                self::$noPushReasons[$entity->getEntityTypeId()][$entity->uuid()] = self::NO_PUSH_REASON__NEVER_PUSHED;

                return false;
            }
        }

        $cms_content_sync_disable_optimization = boolval(\Drupal::config('cms_content_sync.debug')
            ->get('cms_content_sync_disable_optimization'));

        if (isset(self::$pushed[$action][$entity_type][$entity_bundle][$entity_uuid][$this->pool->id]) && !$return_only) {
            return self::$pushed[$action][$entity_type][$entity_bundle][$entity_uuid][$this->pool->id];
        }
        if (SyncIntent::ACTION_CREATE == $action) {
            if (isset(self::$pushed[SyncIntent::ACTION_UPDATE][$entity_type][$entity_bundle][$entity_uuid][$this->pool->id]) && !$return_only) {
                return self::$pushed[SyncIntent::ACTION_UPDATE][$entity_type][$entity_bundle][$entity_uuid][$this->pool->id];
            }
        } elseif (SyncIntent::ACTION_UPDATE == $action) {
            if (isset(self::$pushed[SyncIntent::ACTION_CREATE][$entity_type][$entity_bundle][$entity_uuid][$this->pool->id]) && !$return_only) {
                return self::$pushed[SyncIntent::ACTION_CREATE][$entity_type][$entity_bundle][$entity_uuid][$this->pool->id];
            }
        }

        // No need to retry from this point onward.
        self::$pushed[$action][$entity_type][$entity_bundle][$entity_uuid][$this->pool->id] = true;

        $proceed = true;

        $operation = $this->operation;

        if (SyncIntent::ACTION_DELETE === $action) {
            $operation
                ->delete(SyncIntent::ACTION_DELETE === $action);
        } else {
            try {
                $proceed = $this->serialize();
            } catch (\Exception $e) {
                $this->saveFailedPush(PushIntent::PUSH_FAILED_INTERNAL_ERROR, $e->getMessage());

                throw new SyncException(SyncException::CODE_ENTITY_API_FAILURE, $e);
            }
        }

        // If the entity didn't change, it doesn't have to be pushed again.
        // Note that we still serialize the entity above. This is required for the hash
        // of all referenced entities to be created (see PushSingle implementation).
        if (!$cms_content_sync_disable_optimization && !$this->entityChanged() && self::PUSH_FORCED != $reason
      && SyncIntent::ACTION_DELETE != $action
      && empty($deletedTranslations[$entity->getEntityTypeId()][$entity->uuid()]) && !$return_only) {
            self::$noPushReasons[$entity->getEntityTypeId()][$entity->uuid()] = self::NO_PUSH_REASON__UNCHANGED;

            return false;
        }

        \Drupal::logger('cms_content_sync')->info('@not @embed PUSH @action @entity_type:@bundle @uuid (hash: @hash) @reason: @message<br>Flow: @flow_id | Pool: @pool_id', [
            '@reason' => $reason,
            '@action' => $action,
            '@entity_type' => $entity_type,
            '@bundle' => $entity_bundle,
            '@uuid' => $entity_uuid,
            '@not' => $proceed ? '' : 'NO',
            '@embed' => $return_only ? 'EMBEDDING' : '',
            '@hash' => $this->operation->getEntityHash(),
            '@message' => $proceed ? t('The entity has been pushed.') : t('The entity handler denied to push this entity.'),
            '@flow_id' => $this->getFlow()->id(),
            '@pool_id' => $this->getPool()->id(),
        ]);

        // Handler chose to deliberately ignore this entity,
        // e.g. a node that wasn't published yet and is not pushed unpublished.
        if (!$proceed) {
            self::$noPushReasons[$entity->getEntityTypeId()][$entity->uuid()] = self::NO_PUSH_REASON__HANDLER_IGNORES;
            $this->saveFailedPush(PushIntent::PUSH_FAILED_HANDLER_DENIED);
            unset(self::$pushed[$action][$entity_type][$entity_bundle][$entity_uuid][$this->pool->id]);

            $this->extendedEntityExportLogMessage($entity);

            return $return_only ? null : false;
        }

        // We need to update the revision timestamp as otherwise the change won't be propagated by the Sync Core.
        if ($this->isQuickEdited) {
            $revision_timestamp = $operation->getProperty('revision_timestamp');
            if (!empty($revision_timestamp[0]['value']) && $revision_timestamp[0]['value'] < $this->getRequestTime()) {
                $revision_timestamp[0]['value'] = $this->getRequestTime();
                $this->operation->setProperty('revision_timestamp', $revision_timestamp);
            }
        }

        $this->entityVersionHash = $this->flow->getEntityTypeConfig($entity->getEntityTypeId(), $entity->bundle())['version'];

        // If the version changed, UPDATE becomes CREATE instead and DELETE requests must be performed against the old
        // version, as otherwise they would result in a 404 Not Found response.
        if ($this->entityVersionHash != $this->entity_status->getEntityTypeVersion()) {
            if (SyncIntent::ACTION_UPDATE == $action) {
                $action = SyncIntent::ACTION_CREATE;
            } elseif (SyncIntent::ACTION_DELETE == $action) {
                $this->entityVersionHash = $this->entity_status->getEntityTypeVersion();
            }
        }

        if ($return_only) {
            self::$pushed[$action][$entity_type][$entity_bundle][$entity_uuid][$this->pool->id] = $this;

            $this->extendedEntityExportLogMessage($entity);

            return $this;
        }

        try {
            $operation->execute();
        } catch (SyncCoreException $e) {
            \Drupal::logger('cms_content_sync')->error(
                'Failed to @action entity @entity_type-@entity_bundle @entity_uuid'.PHP_EOL.'@message'.PHP_EOL.'Got status code @status_code @reason_phrase with body:'.PHP_EOL.'@body<br>Flow: @flow_id | Pool: @pool_id',
                [
                    '@action' => $action,
                    '@entity_type' => $entity_type,
                    '@entity_bundle' => $entity_bundle,
                    '@entity_uuid' => $entity_uuid,
                    '@message' => $e->getMessage(),
                    '@status_code' => $e->getStatusCode(),
                    '@reason_phrase' => $e->getReasonPhrase(),
                    '@body' => $e->getResponseBody().'',
                    '@flow_id' => $this->getFlow()->id(),
                    '@pool_id' => $this->getPool()->id(),
                ]
            );

            $this->saveFailedPush(PushIntent::PUSH_FAILED_REQUEST_FAILED, $e->getMessage());

            throw new SyncException(SyncException::CODE_PUSH_REQUEST_FAILED, $e);
        }

        $this->afterPush($action, $entity);

        return true;
    }

    public function afterPush($action, $entity)
    {
        $this->updateEntityStatusAfterSuccessfulPush($action);

        // Dispatch entity push event to give other modules the possibility to react on it.
        \Drupal::service('event_dispatcher')->dispatch(AfterEntityPush::EVENT_NAME, new AfterEntityPush($entity, $this->pool, $this->flow, $this->reason, $this->action));

        $this->extendedEntityExportLogMessage($entity);
    }

    /**
     * Handle Extended Entity Export logging.
     *
     * @param \Drupal\Core\Entity\EntityInterface $entity
     *                                                    The exported entity
     */
    public function extendedEntityExportLogMessage(EntityInterface $entity)
    {
        $settings = ContentSyncSettings::getInstance();
        if ($settings->getExtendedEntityExportLogging()) {
            $serializer = \Drupal::service('serializer');
            $data = $serializer->serialize($entity, 'json', ['plugin_id' => 'entity']);

            \Drupal::logger('cms_content_sync_entity_export_log')->debug('%entity_type - %uuid <br>Data: <br><pre><code>%data</code></pre>', [
                '%entity_type' => $entity->getEntityTypeId(),
                '%uuid' => $entity->uuid(),
                '%data' => $data,
            ]);
        }
    }

    /**
     * @param string $action
     * @param null   $parent_type
     * @param null   $parent_uuid
     */
    public function updateEntityStatusAfterSuccessfulPush($action = SyncIntent::ACTION_CREATE, $parent_type = null, $parent_uuid = null)
    {
        $this->entity_status->setEntityPushHash($this->operation->getEntityHash());

        if (!$this->entity_status->getLastPush() && !$this->entity_status->getLastPull() && !empty($this->operation->getProperty('url'))) {
            $this->entity_status->set('source_url', $this->operation->getProperty('url'));
        }

        $push = $this->getEntityChangedTime($this->entity);
        $this->entity_status->setLastPush($push);

        if (SyncIntent::ACTION_DELETE == $action) {
            $this->entity_status->isDeleted(true);
            $this->pool->markDeleted($this->entity->getEntityTypeId(), $this->entity->uuid());
        }

        if ($this->entityVersionHash != $this->entity_status->getEntityTypeVersion()) {
            $this->entity_status->setEntityTypeVersion($this->entityVersionHash);
        }

        if ($parent_type && $parent_uuid) {
            $this->entity_status->wasPushedEmbedded(true);
            $this->entity_status->setParentEntity($parent_type, $parent_uuid);
        } else {
            $this->entity_status->wasPushedEmbedded(false);
        }

        $this->entity_status->save();

        foreach ($this->embeddedPushIntents as $intent) {
            $intent->updateEntityStatusAfterSuccessfulPush(SyncIntent::ACTION_CREATE, $this->entityType, $this->uuid);
        }
    }

    /**
     * Check whether the given entity is currently being pushed. Useful to check
     * against hierarchical references as for nodes and menu items for example.
     *
     * @param string      $entity_type
     *                                 The entity type to check for
     * @param string      $uuid
     *                                 The UUID of the entity in question
     * @param string      $pool
     *                                 The pool to push to
     * @param null|string $action
     *                                 See ::ACTION_*
     *
     * @return bool
     */
    public static function isPushing($entity_type, $uuid, $pool = null, $action = null)
    {
        foreach (self::$pushed as $do => $types) {
            if ($action ? $do != $action : SyncIntent::ACTION_DELETE == $do) {
                continue;
            }
            if (!isset($types[$entity_type])) {
                continue;
            }
            foreach ($types[$entity_type] as $bundle => $entities) {
                if (empty($pool)) {
                    if (!empty($entities[$uuid])) {
                        return true;
                    }
                } else {
                    if (!empty($entities[$uuid][$pool])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Helper function to push an entity and throw errors if anything fails.
     *
     * @param \Drupal\Core\Entity\EntityInterface  $entity
     *                                                            The entity to push
     * @param string                               $reason
     *                                                            {@see Flow::PUSH_*}
     * @param string                               $action
     *                                                            {@see ::ACTION_*}
     * @param \Drupal\cms_content_sync\Entity\Flow $flow
     *                                                            The flow to be used. If none is given, all flows that may push this
     *                                                            entity will be asked to do so for all relevant pools.
     * @param \Drupal\cms_content_sync\Entity\Pool $pool
     *                                                            The pool to be used. If not set, all relevant pools for the flow will be
     *                                                            used one after another.
     * @param bool                                 $return_intent
     *                                                            Return the PushIntent operation instead of
     *                                                            executing it. Used to embed entities.
     *
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\Core\Entity\EntityStorageException
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * @return bool|PushIntent Whether the entity is configured to be pushed or not.
     *                         if $return_only is given, this will return the serialized entity to embed
     *                         or NULL.
     */
    public static function pushEntity(EntityInterface $entity, $reason, $action, Flow $flow = null, Pool $pool = null, $return_intent = false)
    {
        if (!$flow) {
            $flows = self::getFlowsForEntity($entity, $reason, $action);
            if (!count($flows)) {
                return false;
            }

            $result = false;
            foreach ($flows as $flow) {
                if ($return_intent) {
                    $result = self::pushEntity($entity, $reason, $action, $flow, null, true);
                    if ($result) {
                        return $result;
                    }
                } else {
                    $result |= self::pushEntity($entity, $reason, $action, $flow);
                }
            }

            return $result;
        }

        if (!$pool) {
            $pools = $flow->getPoolsToPushTo($entity, $reason, $action, true);
            $result = false;
            foreach ($pools as $pool) {
                $infos = EntityStatus::getInfosForEntity($entity->getEntityTypeId(), $entity->uuid(), ['pool' => $pool->label()]);
                $cancel = false;
                foreach ($infos as $info) {
                    if (!$info->getFlow()) {
                        continue;
                    }

                    if (!$info->getLastPull()) {
                        continue;
                    }

                    $config = $info->getFlow()->getEntityTypeConfig($entity->getEntityTypeId(), $entity->bundle())['import_updates'];

                    if (in_array($config, [PullIntent::PULL_UPDATE_FORCE_AND_FORBID_EDITING, PullIntent::PULL_UPDATE_FORCE_UNLESS_OVERRIDDEN])) {
                        $cancel = true;

                        break;
                    }
                }

                if ($cancel) {
                    continue;
                }

                if ($return_intent) {
                    $result = self::pushEntity($entity, $reason, $action, $flow, $pool, true);
                    if ($result) {
                        return $result;
                    }
                } else {
                    $result |= self::pushEntity($entity, $reason, $action, $flow, $pool);
                }
            }

            return $result;
        }

        $intent = new PushIntent($flow, $pool, $reason, $action, $entity);

        return $intent->execute($return_intent);
    }

    /**
     * Get the reason why a push has not happened.
     *
     * @param \Drupal\Core\Entity\EntityInterface $entity
     * @param bool                                $as_message
     *
     * @return null|Exception|string see self::$noPushReasons
     */
    public static function getNoPushReason($entity, $as_message = false)
    {
        // If push wasn't even tried, no pool has been assigned.
        if (empty(self::$noPushReasons[$entity->getEntityTypeId()][$entity->uuid()])) {
            $issue = self::NO_PUSH_REASON__NO_POOL;
        } else {
            $issue = self::$noPushReasons[$entity->getEntityTypeId()][$entity->uuid()];
        }

        if ($as_message) {
            return self::displayNoPushReason(
                $issue
            );
        }

        return $issue;
    }

    /**
     * Get a user message on why the push failed.
     *
     * @param Exception|string $reason
     *                                 The reason from self::getNoPushReason()
     *
     * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
     */
    public static function displayNoPushReason($reason)
    {
        if ($reason instanceof \Exception) {
            return $reason->getMessage();
        }

        switch ($reason) {
      case self::NO_PUSH_REASON__HANDLER_IGNORES:
        return t('The configuration forbids the push.');

      case self::NO_PUSH_REASON__JUST_PULLED:
        return t('The entity has just been pulled and cannot be pushed immediately with the same request.');

      case self::NO_PUSH_REASON__NEVER_PUSHED:
        return t('The entity has not been pushed before, so pushing the deletion doesn\'t have any effect.');

      case self::NO_PUSH_REASON__UNCHANGED:
        return t('The entity has not changed since it\'s last push.');

      default:
        return t('The entity doesn\'t have any Pool assigned.');
    }
    }

    /**
     * Helper function to push an entity and display the user the results. If
     * you want to make changes programmatically, use ::pushEntity() instead.
     *
     * @param \Drupal\Core\Entity\EntityInterface  $entity
     *                                                     The entity to push
     * @param string                               $reason
     *                                                     {@see Flow::PUSH_*}
     * @param string                               $action
     *                                                     {@see ::ACTION_*}
     * @param \Drupal\cms_content_sync\Entity\Flow $flow
     *                                                     The flow to be used. If none is given, all flows that may push this
     *                                                     entity will be asked to do so for all relevant pools.
     * @param \Drupal\cms_content_sync\Entity\Pool $pool
     *                                                     The pool to be used. If not set, all relevant pools for the flow will be
     *                                                     used one after another.
     *
     * @return bool whether the entity is configured to be pushed or not
     */
    public static function pushEntityFromUi(EntityInterface $entity, $reason, $action, Flow $flow = null, Pool $pool = null)
    {
        $messenger = \Drupal::messenger();

        try {
            $status = self::pushEntity($entity, $reason, $action, $flow, $pool);

            if ($status) {
                $link = 'node' === $entity->getEntityTypeId() && SyncIntent::ACTION_DELETE != $action && Migration::useV2()
          ? \Drupal\Core\Link::createFromRoute('View progress', 'cms_content_sync.content_sync_status', ['node' => $entity->id()])->toString()
          : '';
                if (SyncIntent::ACTION_DELETE == $action) {
                    $message = t('%label has been pushed to your @repository.', ['@repository' => _cms_content_sync_get_repository_name(), '%label' => $entity->getEntityTypeId()]);
                } else {
                    $message = t('%label has been pushed to your @repository. @view_progress', [
                        '@repository' => _cms_content_sync_get_repository_name(),
                        '%label' => $entity->label(),
                        '@view_progress' => $link,
                    ]);
                }
                $messenger->addMessage($message);

                return true;
            }

            return false;
        } catch (SyncException $e) {
            $root_exception = $e->getRootException();
            $message = $root_exception ? $root_exception->getMessage() : (
                $e->errorCode == $e->getMessage() ? '' : $e->getMessage()
            );
            if ($message) {
                $messenger->addWarning(t('Failed to push %label to your @repository (%code). Message: %message', [
                    '@repository' => _cms_content_sync_get_repository_name(),
                    '%label' => $entity->label(),
                    '%code' => $e->errorCode,
                    '%message' => $message,
                ]));

                \Drupal::logger('cms_content_sync')->error('Failed to push %label to your @repository (%code). Message: %message<br>Error stack: %error_stack', [
                    '@repository' => _cms_content_sync_get_repository_name(),
                    '%label' => $entity->label(),
                    '%code' => $e->errorCode,
                    '%message' => $message,
                    '%error_stack' => $root_exception ? $root_exception->getTraceAsString() : '',
                ]);
            } else {
                $messenger->addWarning(t('Failed to push %label to your @repository (%code).', [
                    '@repository' => _cms_content_sync_get_repository_name(),
                    '%label' => $entity->label(),
                    '%code' => $e->errorCode,
                ]));

                \Drupal::logger('cms_content_sync')->error('Failed to push %label to your @repository (%code).', [
                    '@repository' => _cms_content_sync_get_repository_name(),
                    '%label' => $entity->label(),
                    '%code' => $e->errorCode,
                ]);
            }
            self::$noPushReasons[$entity->getEntityTypeId()][$entity->uuid()] = $e;

            return true;
        }
    }

    /**
     * Push the provided entity along with the processed entity by embedding it
     * right into the current entity. This means the embedded entity can't be used
     * outside of it's parent entity in any way. This is used for field
     * collections right now.
     *
     * @param \Drupal\Core\Entity\EntityInterface $entity
     *                                                     The referenced entity to push as well
     * @param array                               $details
     *                                                     {@see SyncIntent::getEmbedEntityDefinition}
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\Core\Entity\EntityStorageException
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * @return array|object the definition you can store via {@see SyncIntent::setField} and on the other end receive via {@see SyncIntent::getField}
     */
    public function embed($entity, $details = null)
    {
        return $this->embedForFlowAndPool($entity, $details, $this->flow, $this->pool);
    }

    /**
     * Push the provided entity as a dependency meaning the referenced entity
     * is available before this entity so it can be referenced on the remote site
     * immediately like bricks or paragraphs.
     *
     * @param \Drupal\Core\Entity\EntityInterface $entity
     *                                                               The referenced entity to push as well
     * @param array                               $details
     *                                                               {@see SyncIntent::getEmbedEntityDefinition}
     * @param bool                                $push_to_same_pool
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\Core\Entity\EntityStorageException
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * @return array|object the definition you can store via {@see SyncIntent::setField} and on the other end receive via {@see SyncIntent::getField}
     */
    public function addDependency($entity, $details = null, $push_to_same_pool = true)
    {
        if ($this->pool->getClient() instanceof \EdgeBox\SyncCore\V2\SyncCore) {
            return $this->embed($entity, $details);
        }
        if (in_array($entity->getEntityTypeId(), ContentSyncSettings::getInstance()->getEmbedEntities())) {
            return $this->embed($entity, $details);
        }

        $pools = $this->pushReference($entity, true, $push_to_same_pool);

        // Not pushed? Just using our current pool then to de-reference it at the remote site if the entity exists.
        if (empty($pools)) {
            return $this->operation->addDependency(
                $entity->getEntityTypeId(),
                $entity->bundle(),
                $entity->uuid(),
                EntityHandlerPluginManager::isEntityTypeConfiguration($entity->getEntityType()) ? $entity->id() : null,
                Flow::getEntityTypeVersion($entity->getEntityTypeId(), $entity->bundle()),
                [$this->pool->id],
                $entity->language()->getId(),
                $entity->label(),
                $details
            );
        }

        $result = null;

        foreach ($pools as $pool_id) {
            $result = $this->operation->addDependency(
                $entity->getEntityTypeId(),
                $entity->bundle(),
                $entity->uuid(),
                EntityHandlerPluginManager::isEntityTypeConfiguration($entity->getEntityType()) ? $entity->id() : null,
                Flow::getEntityTypeVersion($entity->getEntityTypeId(), $entity->bundle()),
                [$pool_id],
                $entity->language()->getId(),
                $entity->label(),
                $details
            );
        }

        return $result;
    }

    /**
     * Push the provided entity as a simple reference. There is no guarantee the
     * referenced entity will be available on the remote site as well, but if it
     * is, it will be de-referenced. If you need the referenced entity to be available,
     * use {@see PushIntent::addDependency} instead.
     *
     * @param \Drupal\Core\Entity\EntityInterface $entity
     *                                                     The referenced entity to push as well
     * @param array                               $details
     *                                                     {@see SyncIntent::getEmbedEntityDefinition}
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\Core\Entity\EntityStorageException
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * @return array|object the definition you can store via {@see SyncIntent::setField} and on the other end receive via {@see SyncIntent::getField}
     */
    public function addReference($entity, $details = null)
    {
        // Check if the Pool has been selected manually. In this case, we need to embed the entity despite the AUTO PUSH not being set.
        $statuses = EntityStatus::getInfosForEntity($entity->getEntityTypeId(), $entity->uuid(), ['flow' => $this->flow->id()]);

        if (in_array($entity->getEntityTypeId(), ContentSyncSettings::getInstance()->getEmbedEntities())) {
            $result = null;
            foreach ($statuses as $status) {
                if ($status->isManualPushEnabled()) {
                    $result = $this->embedForFlowAndPool($entity, $details, $status->getFlow(), $status->getPool());
                }
            }

            if ($result) {
                return $result;
            }

            // Not pushed? Just using our current pool then to de-reference it at the remote site if the entity exists.
            return $this->operation->addReference(
                $entity->getEntityTypeId(),
                $entity->bundle(),
                $entity->uuid(),
                EntityHandlerPluginManager::isEntityTypeConfiguration($entity->getEntityType()) ? $entity->id() : null,
                Flow::getEntityTypeVersion($entity->getEntityTypeId(), $entity->bundle()),
                [$this->pool->id],
                $entity->language()->getId(),
                $entity->label(),
                $details
            );
        }

        foreach ($statuses as $status) {
            if ($status->isManualPushEnabled()) {
                return $this->addDependency($entity, $details, false);
            }
        }

        $pools = $this->pushReference($entity, false);

        // Not pushed? Just using our current pool then to de-reference it at the remote site if the entity exists.
        if (empty($pools)) {
            return $this->operation->addReference(
                $entity->getEntityTypeId(),
                $entity->bundle(),
                $entity->uuid(),
                EntityHandlerPluginManager::isEntityTypeConfiguration($entity->getEntityType()) ? $entity->id() : null,
                Flow::getEntityTypeVersion($entity->getEntityTypeId(), $entity->bundle()),
                [$this->pool->id],
                $entity->language()->getId(),
                $entity->label(),
                $details
            );
        }

        $result = null;

        foreach ($pools as $pool_id) {
            $result = $this->operation->addReference(
                $entity->getEntityTypeId(),
                $entity->bundle(),
                $entity->uuid(),
                EntityHandlerPluginManager::isEntityTypeConfiguration($entity->getEntityType()) ? $entity->id() : null,
                Flow::getEntityTypeVersion($entity->getEntityTypeId(), $entity->bundle()),
                [$pool_id],
                $entity->language()->getId(),
                $entity->label(),
                $details
            );
        }

        return $result;
    }

    /**
     * Set the value of the given field. By default every field handler
     * will have a field available for storage when pulling / pushing that
     * accepts all non-associative array-values. Within this array you can
     * use the following types: array, associative array, string, integer, float,
     * boolean, NULL. These values will be JSON encoded when pushing and JSON
     * decoded when pulling. They will be saved in a structured database by
     * Sync Core in between, so you can't pass any non-array value by default.
     *
     * @param string $name
     *                      The name of the field in question
     * @param mixed  $value
     *                      The value to store
     */
    public function setProperty($name, $value)
    {
        // Don't need to store empty values.
        if (null === $value || '' === $value || (is_array($value) && 0 === count($value))) {
            return;
        }

        $this->operation->setProperty($name, $value, $this->activeLanguage);
    }

    /**
     * Save that the pull for the given entity failed.
     *
     * @param string      $failure_reason
     *                                    See PushIntent::PUSH_FAILURE_*
     * @param null|string $message
     *                                    An optional message accompanying this error
     *
     * @throws \Drupal\Core\Entity\EntityStorageException
     */
    protected function saveFailedPush($failure_reason, $message = null)
    {
        $soft_fails = [
            PushIntent::PUSH_FAILED_HANDLER_DENIED,
            PushIntent::PUSH_FAILED_UNCHANGED,
        ];

        $soft = in_array($failure_reason, $soft_fails);

        $this->entity_status->didPushFail(true, $soft, [
            'error' => $failure_reason,
            'action' => $this->getAction(),
            'reason' => $this->getReason(),
            'message' => $message,
        ]);

        $this->entity_status->save();
    }

    /**
     * @return int
     */
    protected function getRequestTime()
    {
        return (int) $_SERVER['REQUEST_TIME'];
    }

    /**
     * Get the changed date of the entity. Not all entities provide the required attribute and even those aren't
     * consistently saving it so this method takes care of these exceptions.
     *
     * @todo Check if we should remove this as we're no longer using this changed
     *   date for deciding whether to push an entity or not (using hashes now).
     *
     * @param \Drupal\Core\Entity\EntityInterface $entity
     *
     * @return int
     */
    protected function getEntityChangedTime($entity)
    {
        $request_time = $this->getRequestTime();
        $push = $request_time;

        if ($entity instanceof EntityChangedInterface) {
            $push = $entity->getChangedTime();
            if ($entity instanceof TranslatableInterface) {
                foreach ($entity->getTranslationLanguages(false) as $language) {
                    $translation = $entity->getTranslation($language->getId());
                    /**
                     * @var \Drupal\Core\Entity\EntityChangedInterface $translation
                     */
                    if ($translation->getChangedTime() > $push) {
                        $push = $translation->getChangedTime();
                    }
                }
            }
            // Check if any bricks were updated during this request that this specific entity is referencing
            // Quick edit doesn't update the changed date of the node...... so we have to go and see manually if anything
            // changed by caching it....
            if ($push < $request_time && $this->isQuickEdited) {
                return $request_time;
            }
        }

        if (EntityHandlerPluginManager::isEntityTypeFieldable($entity->getEntityTypeId())) {
            /**
             * @var \Drupal\Core\Entity\EntityFieldManager $entity_field_manager
             */
            $entity_field_manager = \Drupal::service('entity_field.manager');

            /**
             * @var \Drupal\Core\Field\FieldDefinitionInterface[] $fields
             */
            $fields = $entity_field_manager->getFieldDefinitions($entity->getEntityTypeId(), $entity->bundle());

            // Elements that are inline edited in other forms like media elements edited within node forms don't get their
            // timestamp updated even though the file attributes may change like the focal point. So we are checking for file
            // reference fields and check if they were updated in the meantime.
            foreach ($fields as $key => $field) {
                if ('image' === $field->getType()) {
                    $data = $entity->get($key)->getValue();

                    foreach ($data as $delta => $value) {
                        if (empty($value['target_id'])) {
                            continue;
                        }

                        $entityTypeManager = \Drupal::entityTypeManager();
                        $storage = $entityTypeManager->getStorage('file');

                        $target_id = $value['target_id'];
                        $reference = $storage->load($target_id);

                        if (!$reference) {
                            continue;
                        }

                        $sub = $this->getEntityChangedTime($reference);
                        if ($sub > $push) {
                            $push = $sub;
                        }
                    }
                }
            }
        }

        // File entities timestamp doesn't change when focal point is updated and crop entity doesn't provide a changed date.
        if ('file' === $entity->getEntityTypeId()) {
            /**
             * @var \Drupal\file\FileInterface $entity
             */

            // Handle crop entities.
            $moduleHandler = \Drupal::service('module_handler');
            if ($moduleHandler->moduleExists('crop') && $moduleHandler->moduleExists('focal_point')) {
                if (Crop::cropExists($entity->getFileUri(), 'focal_point')) {
                    $crop = Crop::findCrop($entity->getFileUri(), 'focal_point');
                    if ($crop) {
                        $info = EntityStatus::getInfoForEntity('file', $entity->uuid(), $this->flow->id(), $this->pool->id());

                        if ($info) {
                            $position = $crop->position();
                            $last = $info->getData('crop');
                            if (empty($last) || $position['x'] !== $last['x'] || $position['y'] !== $last['y']) {
                                $push = $this->getRequestTime();
                            }
                        }
                    }
                }
            }
        }

        return $push;
    }

    /**
     * Check whether the entity changed at all since the last push.
     *
     * @return bool
     */
    protected function entityChanged()
    {
        $last_hash = $this->entity_status->getEntityPushHash();
        $new_hash = $this->operation->getEntityHash();

        return $last_hash !== $new_hash;
    }

    /**
     * Push the provided entity along with the processed entity by embedding it
     * right into the current entity. This means the embedded entity can't be used
     * outside of it's parent entity in any way. This is used for field
     * collections right now.
     *
     * @param \Drupal\Core\Entity\EntityInterface  $entity
     *                                                      The referenced entity to push as well
     * @param null|array                           $details
     *                                                      {@see SyncIntent::getEmbedEntityDefinition}
     * @param \Drupal\cms_content_sync\Entity\Flow $flow
     * @param \Drupal\cms_content_sync\Entity\Pool $pool
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\Core\Entity\EntityStorageException
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * @return array|object the definition you can store via {@see SyncIntent::setField} and on the other end receive via {@see SyncIntent::getField}
     */
    protected function embedForFlowAndPool($entity, $details, $flow, $pool)
    {
        /**
         * @var PushIntent $embed_entity
         */
        $embed_entity = PushIntent::pushEntity(
            $entity,
            PushIntent::PUSH_AS_DEPENDENCY,
            SyncIntent::ACTION_CREATE,
            $flow,
            $pool,
            true
        );

        if (!$embed_entity) {
            throw new SyncException(SyncException::CODE_UNEXPECTED_EXCEPTION, null, 'Failed to embed entity.');
        }

        $result = $this->operation->embed(
            $entity->getEntityTypeId(),
            $entity->bundle(),
            $entity->uuid(),
            EntityHandlerPluginManager::isEntityTypeConfiguration($entity->getEntityType()) ? $entity->id() : null,
            Flow::getEntityTypeVersion($entity->getEntityTypeId(), $entity->bundle()),
            $embed_entity->getOperation(),
            $details
        );

        $this->embeddedPushIntents[] = $embed_entity;

        return $result;
    }

    /**
     * @param \Drupal\Core\Entity\EntityInterface $entity
     * @param bool                                $dependency
     * @param bool                                $push_to_same_pool
     *
     * @throws \Drupal\Core\Entity\EntityStorageException
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     *
     * @return string[] The IDs of the Pools that were used
     */
    protected function pushReference($entity, $dependency, $push_to_same_pool = false)
    {
        try {
            $all_pools = Pool::getAll();
            $pools = $this->flow->getPoolsToPushTo($entity, PushIntent::PUSH_AS_DEPENDENCY, SyncIntent::ACTION_CREATE, true);
            if (isset($pools[$this->pool->id]) || $push_to_same_pool) {
                $pools = [$this->pool->id => $this->pool] + $pools;
            }
            $used_pools = [];

            $flows = Flow::getAll();
            $flows = [$this->flow->id => $this->flow] + $flows;

            $version = Flow::getEntityTypeVersion($entity->getEntityTypeId(), $entity->bundle());

            foreach ([
                // Prefer Flows that push AS_DEPENDENCY.
                self::PUSH_AS_DEPENDENCY,
                // But then use Flows that push AUTOMATICALLY to support that use-case as well.
                // If an AS_DEPENDENCY Flow has pushed to a specific pool before, the Flow pushing AUTOMATICALLY will not
                // be able to export to that pool again here, that's why the order matters.
                self::PUSH_AUTOMATICALLY,
                // Also push manually exported references if configured.
                self::PUSH_MANUALLY,
            ] as $reason) {
                foreach ($flows as $flow) {
                    if (!$flow->canPushEntity($entity, $reason, SyncIntent::ACTION_CREATE)) {
                        continue;
                    }

                    $export_pools = $flow->getEntityTypeConfig($entity->getEntityTypeId(), $entity->bundle())['export_pools'];

                    // Make sure the first pool we try is the pool of the current parent
                    // push operation.
                    if (isset($export_pools[$this->pool->id])) {
                        $export_pools = [$this->pool->id => $export_pools[$this->pool->id]] + $export_pools;
                    }

                    foreach ($export_pools as $pool_id => $behavior) {
                        if (in_array($pool_id, $used_pools)) {
                            continue;
                        }

                        if (Pool::POOL_USAGE_FORBID == $behavior) {
                            continue;
                        }

                        // If this entity was newly created, it won't have any groups to push to
                        // selected, unless they're FORCED. In this case we add default sync
                        // groups based on the parent entity, as you would expect.
                        if ($dependency) {
                            if (!isset($pools[$pool_id])) {
                                continue;
                            }

                            $pool = $pools[$pool_id];
                            $info = EntityStatus::getInfoForEntity($entity->getEntityTypeId(), $entity->uuid(), $flow, $pool);

                            if (!$info) {
                                if (!$push_to_same_pool) {
                                    continue;
                                }
                                $info = EntityStatus::create([
                                    'flow' => $flow->id,
                                    'pool' => $pool->id,
                                    'entity_type' => $entity->getEntityTypeId(),
                                    'entity_uuid' => $entity->uuid(),
                                    'entity_type_version' => $version,
                                    'flags' => 0,
                                ]);
                            }

                            $info->isPushEnabled(null, true);
                            $info->save();

                            PushIntent::pushEntity($entity, $reason, SyncIntent::ACTION_CREATE, $flow, $pool);
                        } else {
                            $pool = $all_pools[$pool_id];
                            if (Pool::POOL_USAGE_ALLOW == $behavior) {
                                $info = EntityStatus::getInfoForEntity($entity->getEntityTypeId(), $entity->uuid(), $flow, $pool);
                                if (!$info || !$info->isPushEnabled()) {
                                    continue;
                                }
                            }
                        }

                        $info = EntityStatus::getInfoForEntity($entity->getEntityTypeId(), $entity->uuid(), $flow, $pool);

                        // In case the handler denied pushing the entity, we simply ignore the attempt.
                        if (!$info || !$info->getLastPush()) {
                            // Unless we are referencing our parent entity that is also being pushed right now
                            // e.g. a menu item will reference it's parent node but the parent will trigger
                            // the menu item push so the status entity isn't there yet.
                            if (!self::isPushing($entity->getEntityTypeId(), $entity->uuid())) {
                                continue;
                            }
                        }

                        $used_pools[] = $pool_id;

                        // If "push to same pool" is set, we can stop after pushing there.
                        if ($push_to_same_pool && $pool_id === $this->pool->id) {
                            return $used_pools;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->saveFailedPush(PushIntent::PUSH_FAILED_DEPENDENCY_PUSH_FAILED, $e->getMessage());

            throw new SyncException(SyncException::CODE_UNEXPECTED_EXCEPTION, $e);
        }

        return $used_pools;
    }
}
