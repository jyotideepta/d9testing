<?php

namespace Drupal\cms_content_sync;

use Drupal\cms_content_sync\Controller\ContentSyncSettings;
use Drupal\cms_content_sync\Controller\Migration;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\cms_content_sync\Event\AfterEntityPull;
use Drupal\cms_content_sync\Plugin\cms_content_sync\entity_handler\DefaultTaxonomyHandler;
use Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager;
use Drupal\field_collection\Entity\FieldCollectionItem;

class PullIntent extends SyncIntent
{
    /**
     * @var string PULL_DISABLED
     *             Disable pull completely for this entity type, unless forced.
     *             - used as a configuration option
     *             - not used as $action
     */
    public const PULL_DISABLED = 'disabled';

    /**
     * @var string PULL_AUTOMATICALLY
     *             Automatically pull all entities of this entity type.
     *             - used as a configuration option
     *             - used as $action
     */
    public const PULL_AUTOMATICALLY = 'automatically';

    /**
     * @var string PULL_MANUALLY
     *             Pull only some of these entities, chosen manually.
     *             - used as a configuration option
     *             - used as $action
     */
    public const PULL_MANUALLY = 'manually';

    /**
     * @var string PULL_AS_DEPENDENCY
     *             Pull only some of these entities, pulled if other pulled entities
     *             use it.
     *             - used as a configuration option
     *             - used as $action
     */
    public const PULL_AS_DEPENDENCY = 'dependency';

    /**
     * @var string PULL_FORCED
     *             Force the entity to be pulled (as long as a handler is also selected).
     *             Can be used programmatically for custom workflows.
     *             - not used as a configuration option
     *             - used as $action
     */
    public const PULL_FORCED = 'forced';

    /**
     * @var string PULL_UPDATE_IGNORE
     *             Ignore all incoming updates
     */
    public const PULL_UPDATE_IGNORE = 'ignore';

    /**
     * @var string PULL_UPDATE_FORCE
     *             Overwrite any local changes on all updates
     */
    public const PULL_UPDATE_FORCE = 'force';

    /**
     * @var string PULL_UPDATE_FORCE_AND_FORBID_EDITING
     *             Pull all changes and forbid local editors to change the content
     */
    public const PULL_UPDATE_FORCE_AND_FORBID_EDITING = 'force_and_forbid_editing';

    /**
     * @var string PULL_UPDATE_FORCE_UNLESS_OVERRIDDEN
     *             Pull all changes and forbid local editors to change the content unless
     *             they check the "override" checkbox. As long as that is checked, we
     *             ignore any incoming updates in favor of the local changes.
     */
    public const PULL_UPDATE_FORCE_UNLESS_OVERRIDDEN = 'allow_override';

    /**
     * @var string PULL_UPDATE_UNPUBLISHED
     *             Pull all changes and as an unpublished revision. Only available for nodes
     *             that are revisionable.
     */
    public const PULL_UPDATE_UNPUBLISHED = 'pull_update_unpublished';

    /**
     * @var string PULL_FAILED_DIFFERENT_VERSION
     *             The remote entity type version is different to the local entity type version
     */
    public const PULL_FAILED_DIFFERENT_VERSION = 'import_failed_different_version';

    /**
     * @var string PULL_FAILED_INTERNAL_ERROR
     *             An internal Content Sync error occurred when trying to pull the entity
     */
    public const PULL_FAILED_CONTENT_SYNC_ERROR = 'import_failed_content_sync_error';

    /**
     * @var string PULL_FAILED_INTERNAL_ERROR
     *             An unexpected error occurred when trying to pull the entity
     */
    public const PULL_FAILED_INTERNAL_ERROR = 'import_failed_internal_error';

    /**
     * @var string PULL_FAILED_UNKNOWN_POOL
     *             Soft: The provided Pool doesn't exist
     */
    public const PULL_FAILED_UNKNOWN_POOL = 'import_failed_unknown_pool';

    /**
     * @var string PULL_FAILED_NO_FLOW
     *             Soft: No Flow is configured to pull this entity
     */
    public const PULL_FAILED_NO_FLOW = 'import_failed_no_flow';

    /**
     * @var string PULL_FAILED_HANDLER_DENIED
     *             Soft: The pull failed because the handler returned FALSE when executing the pull
     */
    public const PULL_FAILED_HANDLER_DENIED = 'import_failed_handler_denied';

    protected $mergeChanges;

    /**
     * @var array
     */
    protected $overriddenProperties = [];

    /**
     * @var array
     */
    protected $overriddenTranslatedProperties = [];

    /**
     * @var \EdgeBox\SyncCore\Interfaces\Syndication\IPullOperation
     */
    protected $operation;

    /**
     * @var \Drupal\cms_content_sync\Plugin\EntityHandlerInterface
     */
    protected $handler;

    /**
     * SyncIntent constructor.
     *
     * @param \Drupal\cms_content_sync\Entity\Flow                    $flow
     *                                                                             {@see SyncIntent::$sync}
     * @param \Drupal\cms_content_sync\Entity\Pool                    $pool
     *                                                                             {@see SyncIntent::$pool}
     * @param string                                                  $reason
     *                                                                             {@see Flow::PUSH_*} or {@see Flow::PULL_*}
     * @param string                                                  $action
     *                                                                             {@see ::ACTION_*}
     * @param string                                                  $entity_type
     *                                                                             {@see SyncIntent::$entityType}
     * @param string                                                  $bundle
     *                                                                             {@see SyncIntent::$bundle}
     * @param \EdgeBox\SyncCore\Interfaces\Syndication\IPullOperation $operation
     *                                                                             The data provided from Sync Core for pulls.
     *                                                                             Format is the same as in ::getData()
     * @param null                                                    $parent_type
     * @param null                                                    $parent_uuid
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    public function __construct(Flow $flow, Pool $pool, $reason, $action, $entity_type, $bundle, $operation, $parent_type = null, $parent_uuid = null)
    {
        $this->operation = $operation;

        if (EntityHandlerPluginManager::isEntityTypeConfiguration($entity_type)) {
            $entity_id = $this->operation->getId();
        } else {
            $entity_id = null;
        }

        parent::__construct(
            $flow,
            $pool,
            $reason,
            $action,
            $entity_type,
            $bundle,
            $this->operation->getUuid(),
            $entity_id,
            $this->operation->getSourceUrl()
        );

        if ($this->operation->getSourceUrl()) {
            $this->entity_status->set('source_url', $this->operation->getSourceUrl());
        }

        if ($parent_type && $parent_uuid) {
            $this->entity_status->wasPulledEmbedded(true);
            $this->entity_status->setParentEntity($parent_type, $parent_uuid);
        } else {
            $this->entity_status->wasPulledEmbedded(false);
        }

        $this->mergeChanges = PullIntent::PULL_UPDATE_FORCE_UNLESS_OVERRIDDEN == $this->flow->getEntityTypeConfig($this->entityType, $this->bundle)['import_updates']
      && $this->entity_status->isOverriddenLocally();
    }

    /**
     * @return \EdgeBox\SyncCore\Interfaces\Syndication\IPullOperation
     */
    public function getOperation()
    {
        return $this->operation;
    }

    /**
     * Mark the given dependency as missing so it's automatically resolved whenever it gets pulled.
     *
     * @param array       $definition
     * @param null|string $field
     * @param null|array  $data
     */
    public function saveUnresolvedDependency($definition, $field = null, $data = null)
    {
        $reference = $this->operation->loadReference($definition);

        // User references are ignored.
        if (!$reference->getType() || (!$reference->getId() && !$reference->getUuid())) {
            return;
        }

        MissingDependencyManager::saveUnresolvedDependency(
            $reference->getType(),
            $reference->getId() ? $reference->getId() : $reference->getUuid(),
            $this->getEntity(),
            $this->getReason(),
            $field,
            $data
        );
    }

    /**
     * @return bool
     */
    public function shouldMergeChanges()
    {
        return $this->mergeChanges;
    }

    public function getViewUrl()
    {
        return $this->handler->getViewUrl($this->getEntity());
    }

    /**
     * Pull the provided entity.
     *
     * @throws Exception\SyncException
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\Core\Entity\EntityStorageException
     *
     * @return bool
     */
    public function execute()
    {
        $pull = $this->pool->getNewestTimestamp($this->entityType, $this->uuid, true);
        if (!$pull) {
            if (SyncIntent::ACTION_UPDATE == $this->action) {
                $this->action = SyncIntent::ACTION_CREATE;
            }
        } elseif (SyncIntent::ACTION_CREATE == $this->action) {
            $this->action = SyncIntent::ACTION_UPDATE;
        }
        $pull = time();

        $config = $this->flow->getEntityTypeConfig($this->entityType, $this->bundle);
        $handler = $this->flow->getEntityTypeHandler($config);
        $this->handler = $handler;

        self::entityHasBeenPulledFromRemoteSite($this->entityType, $this->uuid, true);

        $result = $handler->pull($this);

        \Drupal::logger('cms_content_sync')->info('@not PULL @action @entity_type:@bundle @uuid @reason: @message<br>Flow: @flow_id | Pool: @pool_id', [
            '@reason' => $this->reason,
            '@action' => $this->action,
            '@entity_type' => $this->entityType,
            '@bundle' => $this->bundle,
            '@uuid' => $this->uuid,
            '@not' => $result ? '' : 'NO',
            '@message' => $result ? t('The entity has been pulled.') : t('The entity handler denied to pull this entity.'),
            '@flow_id' => $this->getFlow()->id(),
            '@pool_id' => $this->getPool()->id(),
        ]);

        // Don't save entity_status entity if entity wasn't pulled anyway.
        if (!$result) {
            return false;
        }

        // Need to save after setting timestamp to prevent exception.
        $this->entity_status->setLastPull($pull);
        $this->pool->setTimestamp($this->entityType, $this->uuid, $pull, true);
        $this->entity_status->isDeleted(SyncIntent::ACTION_DELETE == $this->action);
        $this->entity_status->save();

        if (SyncIntent::ACTION_DELETE == $this->action) {
            $this->pool->markDeleted($this->entityType, $this->uuid);
        }

        $entity = $this->getEntity();

        // Dispatch EntityExport event to give other modules the possibility to react on it.
        // Ignore deleted entities.
        if ($entity) {
            \Drupal::service('event_dispatcher')->dispatch(AfterEntityPull::EVENT_NAME, new AfterEntityPull($entity, $this));
        }

        // Handle Extended Entity Import logging.
        $settings = ContentSyncSettings::getInstance();
        if ($settings->getExtendedEntityImportLogging()) {
            $url = null;
            if ($entity && $entity->hasLinkTemplate('canonical') && !($entity instanceof FieldCollectionItem)) {
                $url = $entity->toUrl('canonical', ['absolute' => true])
                    ->toString(true)
                    ->getGeneratedUrl();
            }

            $serializer = \Drupal::service('serializer');
            $data = $serializer->serialize($this->getOperation()->getResponseBody($url), 'json', ['plugin_id' => 'entity']);

            \Drupal::logger('cms_content_sync_entity_import_log')->debug('%entity_type - %uuid <br>Data: <br><pre><code>%data</code></pre>', [
                '%entity_type' => $entity->getEntityTypeId(),
                '%uuid' => $entity->uuid(),
                '%data' => $data,
            ]);
        }

        $this->resolveMissingDependencies();

        if (Migration::useV2() && !Migration::alwaysUseV2()) {
            Migration::entityUsedV2($this->flow->id, $entity->getEntityTypeId(), $entity->bundle(), $entity->uuid(), EntityHandlerPluginManager::isEntityTypeConfiguration($entity->getEntityType()) ? $entity->id() : null, false);
        }

        return true;
    }

    /**
     * Check if the provided entity has just been pulled by Sync Core in this
     * very request. In this case it doesn't make sense to perform a remote
     * request telling Sync Core it has been created/updated/deleted
     * (it will know as a result of this current request).
     *
     * @param string $entity_type
     *                            The entity type
     * @param string $entity_uuid
     *                            The entity UUID
     * @param bool   $set
     *                            If TRUE, this entity will be set to have been pulled at this request
     *
     * @return bool
     */
    public static function entityHasBeenPulledFromRemoteSite($entity_type = null, $entity_uuid = null, $set = false)
    {
        static $entities = [];

        if (!$entity_type) {
            return !empty($entities);
        }

        if ($set) {
            return $entities[$entity_type][$entity_uuid] = true;
        }

        return !empty($entities[$entity_type][$entity_uuid]);
    }

    /**
     * Restore an entity that was added via
     * {@see SyncIntent::embedEntityDefinition} or
     * {@see SyncIntent::embedEntity}.
     *
     * @param array $definition
     *                          The definition you saved in a field and gotten
     *                          back when calling one of the mentioned functions above
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\Core\Entity\EntityStorageException
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     *
     * @return \Drupal\Core\Entity\EntityInterface the restored entity
     */
    public function loadEmbeddedEntity($definition)
    {
        $reference = $this->operation->loadReference($definition);
        if (empty($reference) || !$reference->getType() || !$reference->getBundle()) {
            return null;
        }

        $expected_version = Flow::getEntityTypeVersion(
            $reference->getType(),
            $reference->getBundle()
        );
        if ($expected_version != $reference->getVersion()) {
            \Drupal::logger('cms_content_sync')->warning('Outdated reference to @entity_type:@bundle: Remote version @remote_version doesn\'t match local version @local_version<br>Flow: @flow_id | Pool: @pool_id', [
                '@entity_type' => $reference->getType(),
                '@bundle' => $reference->getBundle(),
                '@remote_version' => $reference->getVersion(),
                '@local_version' => $expected_version,
                '@flow_id' => $this->getFlow()->id(),
                '@pool_id' => $this->getPool()->id(),
            ]);
        }

        if ($reference->isEmbedded()) {
            $embedded_entity = $reference->getEmbeddedEntity();
            if (empty($embedded_entity)) {
                return null;
            }

            $pool_id = $reference->getPoolIds()[0];
            $pool = Pool::getAll()[$pool_id];
            if (empty($pool)) {
                return null;
            }

            $entity_type_name = $reference->getType();
            $entity_bundle = $reference->getBundle();
            $flow = Flow::getFlowForApiAndEntityType($pool, $entity_type_name, $entity_bundle, PullIntent::PULL_AS_DEPENDENCY, SyncIntent::ACTION_CREATE);
            if (!$flow) {
                return null;
            }

            $intent = new PullIntent($flow, $pool, PullIntent::PULL_AS_DEPENDENCY, SyncIntent::ACTION_CREATE, $entity_type_name, $entity_bundle, $embedded_entity, $this->entityType, $this->uuid);
            $status = $intent->execute();
            if (!$status) {
                return null;
            }

            return $intent->getEntity();
        }

        if (empty($reference->getId())) {
            $entity = \Drupal::service('entity.repository')->loadEntityByUuid(
                $reference->getType(),
                $reference->getUuid()
            );
        } else {
            $entity = \Drupal::entityTypeManager()->getStorage($reference->getType())->load($reference->getId());
        }

        // Taxonomy terms can be mapped by their name.
        if (!$entity && !empty($reference->getName())) {
            $config = $this->flow->getEntityTypeConfig($reference->getType(), $reference->getBundle());
            if (!empty($config) && !empty($config['handler_settings'][DefaultTaxonomyHandler::MAP_BY_LABEL_SETTING])) {
                $entity_type = \Drupal::entityTypeManager()->getDefinition($reference->getType());
                $label_property = $entity_type->getKey('label');

                $existing = \Drupal::entityTypeManager()->getStorage($reference->getType())->loadByProperties([
                    $label_property => $reference->getName(),
                ]);

                $entity = reset($existing);
            }
        }

        return $entity;
    }

    /**
     * Get all embedded entity data besides the predefined keys.
     * Images for example have "alt" and "title" in addition to the file reference.
     *
     * @param $definition
     *
     * @return array
     */
    public function getEmbeddedEntityData($definition)
    {
        $reference = $this->operation->loadReference($definition);

        return $reference->getDetails();
    }

    /**
     * Get all field values at once for the currently active language.
     *
     * @return array all field values for the active language
     */
    public function getOverriddenProperties()
    {
        if ($this->activeLanguage) {
            if (!isset($this->overriddenTranslatedProperties[$this->activeLanguage])) {
                return null;
            }

            return $this->overriddenTranslatedProperties[$this->activeLanguage];
        }

        return $this->overriddenProperties;
    }

    /**
     * Provide the value of a field you stored when pushing by using.
     *
     * @see SyncIntent::setField()
     *
     * @param string $name
     *                     The name of the field to restore
     *
     * @return mixed the value you stored for this field
     */
    public function getProperty($name)
    {
        $overridden = $this->getOverriddenProperties();

        return isset($overridden[$name]) ? $overridden[$name] : $this->operation->getProperty($name, $this->activeLanguage);
    }

    /**
     * Overwrite the value for the given field to preprocess the values or access
     * them at a later stage.
     *
     * @param string $name
     *                      The name of the field in question
     * @param mixed  $value
     *                      The value to store
     */
    public function overwriteProperty($name, $value)
    {
        if ($this->activeLanguage) {
            $this->overriddenTranslatedProperties[$this->activeLanguage][$name] = $value;

            return;
        }

        $this->overriddenProperties[$name] = $value;
    }

    /**
     * Get all languages for field translations that are currently used.
     */
    public function getTranslationLanguages()
    {
        return $this->operation->getUsedTranslationLanguages();
    }

    /**
     * Resolve all references to the entity that has just been pulled if they're missing at other content.
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\Core\Entity\EntityStorageException
     */
    protected function resolveMissingDependencies()
    {
        // Ignore deleted entities.
        if ($this->getEntity()) {
            MissingDependencyManager::resolveDependencies($this->getEntity());
        }
    }
}
