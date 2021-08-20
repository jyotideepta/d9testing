<?php

namespace Drupal\cms_content_sync;

use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\cms_content_sync\Exception\SyncException;
use Drupal\Core\Entity\EntityInterface;

/**
 * Class SyncIntent.
 *
 * For every pull and push of every entity, an instance of this class is
 * created and passed through the entity and field handlers. When pushing,
 * you can set field values and embed entities. When pushing, you can
 * receive these values back and resolve the entity references you saved.
 *
 * The same class is used for push and pull to allow adjusting values
 * with hook integration.
 */
abstract class SyncIntent
{
    /**
     * @var string ACTION_CREATE
     *             push/pull the creation of this entity
     */
    public const ACTION_CREATE = 'create';

    /**
     * @var string ACTION_UPDATE
     *             push/pull the update of this entity
     */
    public const ACTION_UPDATE = 'update';

    /**
     * @var string ACTION_DELETE
     *             push/pull the deletion of this entity
     */
    public const ACTION_DELETE = 'delete';

    /**
     * @var string ACTION_DELETE_TRANSLATION
     *             Drupal doesn't update the ->getTranslationStatus($langcode) to
     *             TRANSLATION_REMOVED before calling hook_entity_translation_delete, so we
     *             need to use a custom action to circumvent deletions of translations of
     *             entities not being handled. This is only used for calling the
     *             ->pushEntity function. It will then be replaced by a simple
     *             ::ACTION_UPDATE.
     */
    public const ACTION_DELETE_TRANSLATION = 'delete translation';
    /**
     * @var \Drupal\cms_content_sync\Entity\Flow the synchronization this request spawned at
     * @var Pool
     * @var string                               entity type of the processed entity
     * @var string                               bundle of the processed entity
     * @var string                               UUID of the processed entity
     * @var array                                the field values for the untranslated entity
     * @var array                                The entities that should be processed along with this entity. Each entry is an array consisting of all SyncIntent::_*KEY entries.
     * @var string                               the currently active language
     * @var array                                the field values for the translation of the entity per language as key
     */
    protected $pool;
    protected $reason;
    protected $action;
    protected $entity;
    protected $flow;
    protected $entityType;
    protected $bundle;
    protected $uuid;
    protected $id;
    protected $activeLanguage;

    /**
     * @var \Drupal\cms_content_sync\Entity\EntityStatus
     */
    protected $entity_status;

    /**
     * SyncIntent constructor.
     *
     * @param \Drupal\cms_content_sync\Entity\Flow $flow
     *                                                          {@see SyncIntent::$sync}
     * @param \Drupal\cms_content_sync\Entity\Pool $pool
     *                                                          {@see SyncIntent::$pool}
     * @param string                               $reason
     *                                                          {@see Flow::PUSH_*} or {@see Flow::PULL_*}
     * @param string                               $action
     *                                                          {@see ::ACTION_*}
     * @param string                               $entity_type
     *                                                          {@see SyncIntent::$entityType}
     * @param string                               $bundle
     *                                                          {@see SyncIntent::$bundle}
     * @param string                               $uuid
     *                                                          {@see SyncIntent::$uuid}
     * @param null                                 $id
     * @param string                               $source_url
     *                                                          The source URL if pulled or NULL if pushed from this site
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    public function __construct(Flow $flow, Pool $pool, $reason, $action, $entity_type, $bundle, $uuid, $id = null, $source_url = null)
    {
        $this->flow = $flow;
        $this->pool = $pool;
        $this->reason = $reason;
        $this->action = $action;
        $this->entityType = $entity_type;
        $this->bundle = $bundle;
        $this->uuid = $uuid;
        $this->id = $id;
        $this->entity_status = EntityStatus::getInfoForEntity($entity_type, $uuid, $flow, $pool);

        if (!$this->entity_status) {
            $this->entity_status = EntityStatus::create([
                'flow' => $this->flow->id,
                'pool' => $this->pool->id,
                'entity_type' => $entity_type,
                'entity_uuid' => $uuid,
                'entity_type_version' => Flow::getEntityTypeVersion($entity_type, $bundle),
                'flags' => 0,
                'source_url' => $source_url,
            ]);
        }
    }

    /**
     * Execute the intent.
     *
     * @return bool
     */
    abstract public function execute();

    /**
     * @return string
     */
    public function getReason()
    {
        return $this->reason;
    }

    /**
     * @return string
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     * @return \Drupal\cms_content_sync\Entity\Flow
     */
    public function getFlow()
    {
        return $this->flow;
    }

    /**
     * @return \Drupal\cms_content_sync\Entity\Pool
     */
    public function getPool()
    {
        return $this->pool;
    }

    /**
     * @return \Drupal\Core\Entity\EntityInterface
     *                                             The entity of the intent, if it already exists locally
     */
    public function getEntity()
    {
        if (!$this->entity) {
            if ($this->id) {
                $entity = \Drupal::entityTypeManager()
                    ->getStorage($this->entityType)
                    ->load($this->id);
            } else {
                $entity = \Drupal::service('entity.repository')
                    ->loadEntityByUuid($this->entityType, $this->uuid);
            }

            if ($entity) {
                $this->setEntity($entity);
            }
        }

        return $this->entity;
    }

    /**
     * Returns the entity status.
     */
    public function getEntityStatus()
    {
        return $this->entity_status;
    }

    /**
     * Set the entity when pulling (may not be saved yet then).
     *
     * @param \Drupal\Core\Entity\EntityInterface $entity
     *                                                    The entity you just created
     *
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     *
     * @return $this|EntityInterface|TranslatableInterface
     */
    public function setEntity(EntityInterface $entity)
    {
        if ($entity == $this->entity) {
            return $this->entity;
        }

        if ($this->entity) {
            throw new SyncException(SyncException::CODE_INTERNAL_ERROR, null, 'Attempting to re-set existing entity.');
        }

        /**
         * @var \Drupal\Core\Entity\EntityInterface       $entity
         * @var \Drupal\Core\Entity\TranslatableInterface $entity
         */
        $this->entity = $entity;
        if ($this->entity) {
            if ($this->activeLanguage) {
                $this->entity = $this->entity->getTranslation($this->activeLanguage);
            }
        }

        return $this->entity;
    }

    /**
     * Retrieve a value you stored before via ::setstatusData().
     *
     * @see EntityStatus::getData()
     *
     * @param string|string[] $key
     *                             The key to retrieve
     *
     * @return mixed whatever you previously stored here
     */
    public function getStatusData($key)
    {
        return $this->entity_status ? $this->entity_status->getData($key) : null;
    }

    /**
     * Store a key=>value pair for later retrieval.
     *
     * @see EntityStatus::setData()
     *
     * @param string|string[] $key
     *                               The key to store the data against. Especially
     *                               field handlers should use nested keys like ['field','[name]','[key]'].
     * @param mixed           $value
     *                               Whatever simple value you'd like to store
     *
     * @return bool
     */
    public function setStatusData($key, $value)
    {
        if (!$this->entity_status) {
            return false;
        }
        $this->entity_status->setData($key, $value);

        return true;
    }

    /**
     * Change the language used for provided field values. If you want to add a
     * translation of an entity, the same SyncIntent is used. First, you
     * add your fields using self::setField() for the untranslated version.
     * After that you call self::changeTranslationLanguage() with the language
     * identifier for the translation in question. Then you perform all the
     * self::setField() updates for that language and eventually return to the
     * untranslated entity by using self::changeTranslationLanguage() without
     * arguments.
     *
     * @param string $language
     *                         The identifier of the language to switch to or NULL to reset
     */
    public function changeTranslationLanguage($language = null)
    {
        $this->activeLanguage = $language;
        if ($this->entity) {
            if ($language) {
                $this->entity = $this->entity->getTranslation($language);
            } else {
                $this->entity = $this->entity->getUntranslated();
            }
        }
    }

    /**
     * Return the language that's currently used.
     *
     * @see SyncIntent::changeTranslationLanguage() for a detailed explanation.
     */
    public function getActiveLanguage()
    {
        return $this->activeLanguage;
    }

    /**
     * @see SyncIntent::$entityType
     *
     * @return string
     */
    public function getEntityType()
    {
        return $this->entityType;
    }

    /**
     * @see SyncIntent::$bundle
     */
    public function getBundle()
    {
        return $this->bundle;
    }

    /**
     * @see SyncIntent::$uuid
     */
    public function getUuid()
    {
        return $this->uuid;
    }

    /**
     * @see SyncIntent::$id
     */
    public function getId()
    {
        return $this->id;
    }
}
