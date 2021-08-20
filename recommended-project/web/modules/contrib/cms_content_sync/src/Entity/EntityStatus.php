<?php

namespace Drupal\cms_content_sync\Entity;

use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the "Content Sync - Entity Status" entity type.
 *
 * @ingroup cms_content_sync_entity_status
 *
 * @ContentEntityType(
 *   id = "cms_content_sync_entity_status",
 *   label = @Translation("Content Sync - Entity Status"),
 *   base_table = "cms_content_sync_entity_status",
 *   entity_keys = {
 *     "id" = "id",
 *     "flow" = "flow",
 *     "pool" = "pool",
 *     "entity_uuid" = "entity_uuid",
 *     "entity_type" = "entity_type",
 *     "entity_type_version" = "entity_type_version",
 *     "flags" = "flags",
 *   },
 *   handlers = {
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "storage_schema" = "Drupal\cms_content_sync\EntityStatusStorageSchema",
 *   },
 * )
 */
class EntityStatus extends ContentEntityBase implements EntityStatusInterface
{
    use EntityChangedTrait;

    public const FLAG_UNUSED_CLONED = 0x00000001;
    public const FLAG_DELETED = 0x00000002;
    public const FLAG_USER_ENABLED_PUSH = 0x00000004;
    public const FLAG_EDIT_OVERRIDE = 0x00000008;
    public const FLAG_IS_SOURCE_ENTITY = 0x00000010;
    public const FLAG_PUSH_ENABLED = 0x00000020;
    public const FLAG_DEPENDENCY_PUSH_ENABLED = 0x00000040;
    public const FLAG_LAST_PUSH_RESET = 0x00000080;
    public const FLAG_LAST_PULL_RESET = 0x00000100;
    public const FLAG_PUSH_FAILED = 0x00000200;
    public const FLAG_PULL_FAILED = 0x00000400;
    public const FLAG_PUSH_FAILED_SOFT = 0x00000800;
    public const FLAG_PULL_FAILED_SOFT = 0x00001000;
    public const FLAG_PUSHED_EMBEDDED = 0x00002000;
    public const FLAG_PULLED_EMBEDDED = 0x00004000;

    public const DATA_PULL_FAILURE = 'import_failure';
    public const DATA_PUSH_FAILURE = 'export_failure';
    public const DATA_ENTITY_PUSH_HASH = 'entity_push_hash';
    public const DATA_PARENT_ENTITY = 'parent_entity';

    public const FLOW_NO_FLOW = 'ERROR_STATUS_ENTITY_FLOW';

    /**
     * {@inheritdoc}
     */
    public static function preCreate(EntityStorageInterface $storage_controller, array &$values)
    {
        // Set Entity ID or UUID by default one or the other is not set.
        if (!isset($values['entity_type'])) {
            throw new \Exception(t('The type of the entity is required.'));
        }
        if (!isset($values['flow'])) {
            throw new \Exception(t('The flow is required.'));
        }
        if (!isset($values['pool'])) {
            throw new \Exception(t('The pool is required.'));
        }

        /**
         * @var \Drupal\Core\Entity\EntityInterface $entity
         */
        $entity = \Drupal::service('entity.repository')->loadEntityByUuid($values['entity_type'], $values['entity_uuid']);

        if (!isset($values['entity_type_version'])) {
            $values['entity_type_version'] = Flow::getEntityTypeVersion($entity->getEntityType()->id(), $entity->bundle());

            return;
        }
    }

    /**
     * @param string $entity_type
     * @param string $entity_uuid
     *
     * @throws \Exception
     *
     * @return EntityStatus[]
     */
    public static function getInfoForPool($entity_type, $entity_uuid, Pool $pool)
    {
        if (!$entity_type) {
            throw new \Exception('$entity_type is required.');
        }
        if (!$entity_uuid) {
            throw new \Exception('$entity_uuid is required.');
        }
        /**
         * @var EntityStatus[] $entities
         */
        return \Drupal::entityTypeManager()
            ->getStorage('cms_content_sync_entity_status')
            ->loadByProperties([
                'entity_type' => $entity_type,
                'entity_uuid' => $entity_uuid,
                'pool' => $pool->id,
            ]);
    }

    /**
     * Get a list of all entity status entities for the given entity.
     *
     * @param string $entity_type
     *                            The entity type ID
     * @param string $entity_uuid
     *                            The entity UUID
     * @param array  $filter
     *                            Additional filters. Usually "flow"=>... or "pool"=>...
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     *
     * @return EntityStatus[]
     */
    public static function getInfosForEntity($entity_type, $entity_uuid, $filter = null)
    {
        if (!$entity_type) {
            throw new \Exception('$entity_type is required.');
        }
        if (!$entity_uuid) {
            throw new \Exception('$entity_uuid is required.');
        }
        $base_filter = [
            'entity_type' => $entity_type,
            'entity_uuid' => $entity_uuid,
        ];

        $filters_combined = $base_filter;
        $filter_without_flow = isset($filter['flow']) && (empty($filter['flow']) || self::FLOW_NO_FLOW == $filter['flow']);

        if ($filter_without_flow) {
            $filters_combined = array_merge($filters_combined, [
                'flow' => self::FLOW_NO_FLOW,
            ]);
        } elseif ($filter) {
            $filters_combined = array_merge($filters_combined, $filter);
        }

        /**
         * @var EntityStatus[] $entities
         */
        $entities = \Drupal::entityTypeManager()
            ->getStorage('cms_content_sync_entity_status')
            ->loadByProperties($filters_combined);

        $result = [];

        // If a pull fails, we may create a status entity without a flow assigned.
        // We ignore them for normal functionality, so they're filtered out.
        if ($filter_without_flow) {
            foreach ($entities as $i => $entity) {
                if (!$entity->getFlow()) {
                    $result[] = $entity;
                }
            }
        } else {
            foreach ($entities as $i => $entity) {
                if ($entity->getFlow()) {
                    $result[] = $entity;
                }
            }
        }

        return $result;
    }

    /**
     * @param string      $entity_type
     * @param string      $entity_uuid
     * @param Flow|string $flow
     * @param Pool|string $pool
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Exception
     *
     * @return EntityStatus|mixed
     */
    public static function getInfoForEntity($entity_type, $entity_uuid, $flow, $pool = null)
    {
        if (!$entity_type) {
            throw new \Exception('$entity_type is required.');
        }
        if (!$entity_uuid) {
            throw new \Exception('$entity_uuid is required.');
        }

        $filter = [
            'entity_type' => $entity_type,
            'entity_uuid' => $entity_uuid,
        ];

        if ($pool) {
            $filter['pool'] = is_string($pool) ? $pool : $pool->id;
        }

        if ($flow) {
            $filter['flow'] = is_string($flow) ? $flow : $flow->id;
        } else {
            $filter['flow'] = self::FLOW_NO_FLOW;
        }

        /**
         * @var EntityStatus[] $entities
         */
        $entities = \Drupal::entityTypeManager()
            ->getStorage('cms_content_sync_entity_status')
            ->loadByProperties($filter);

        if (!$flow) {
            foreach ($entities as $entity) {
                if (!$entity->getFlow()) {
                    return $entity;
                }
            }

            return null;
        }

        return reset($entities);
    }

    /**
     * @param $entity
     */
    public function resetStatus()
    {
        $this->setLastPush(null);
        $this->setLastPull(null);
        $this->save();

        // Above cache clearing doesn't work reliably. So we reset the whole entity cache.
        \Drupal::service('cache.entity')->deleteAll();
    }

    /**
     * @throws \Exception
     *
     * @return null|int
     */
    public static function getLastPushForEntity(EntityInterface $entity)
    {
        $entity_status = EntityStatus::getInfosForEntity($entity->getEntityTypeId(), $entity->uuid());
        $latest = null;

        foreach ($entity_status as $info) {
            if ($info->getLastPush() && (!$latest || $info->getLastPush() > $latest)) {
                $latest = $info->getLastPush();
            }
        }

        return $latest;
    }

    /**
     * @throws \Exception
     *
     * @return null|int
     */
    public static function getLastPullForEntity(EntityInterface $entity)
    {
        $entity_status = EntityStatus::getInfosForEntity($entity->getEntityTypeId(), $entity->uuid());
        $latest = null;

        foreach ($entity_status as $info) {
            if ($info->getLastPull() && (!$latest || $info->getLastPull() > $latest)) {
                $latest = $info->getLastPull();
            }
        }

        return $latest;
    }

    /**
     * @param mixed      $entity_type
     * @param mixed      $uuid
     * @param mixed      $field_name
     * @param mixed      $delta
     * @param mixed      $tree_position
     * @param null|mixed $set_flow_id
     * @param null|mixed $set_pool_ids
     * @param null|mixed $set_uuid
     */
    public static function accessTemporaryPushToPoolInfoForField($entity_type, $uuid, $field_name, $delta, $tree_position = [], $set_flow_id = null, $set_pool_ids = null, $set_uuid = null)
    {
        static $field_storage = [];

        if ($set_flow_id && $set_pool_ids) {
            $data = [
                'flow_id' => $set_flow_id,
                'pool_ids' => $set_pool_ids,
                'uuid' => $set_uuid,
            ];
            if (!isset($field_storage[$entity_type][$uuid])) {
                $field_storage[$entity_type][$uuid] = [];
            }
            $setter = &$field_storage[$entity_type][$uuid];
            foreach ($tree_position as $name) {
                if (!isset($setter[$name])) {
                    $setter[$name] = [];
                }
                $setter = &$setter[$name];
            }
            if (!isset($setter[$field_name][$delta])) {
                $setter[$field_name][$delta] = [];
            }
            $setter = &$setter[$field_name][$delta];
            $setter = $data;
        } else {
            if (!empty($field_storage[$entity_type][$uuid])) {
                $value = $field_storage[$entity_type][$uuid];
                foreach ($tree_position as $name) {
                    if (!isset($value[$name])) {
                        return null;
                    }
                    $value = $value[$name];
                }

                return isset($value[$field_name][$delta]) ? $value[$field_name][$delta] : null;
            }
        }

        return null;
    }

    /**
     * @param \Drupal\Core\Entity\EntityInterface $parent_entity
     * @param string                              $parent_field_name
     * @param int                                 $parent_field_delta
     * @param \Drupal\Core\Entity\EntityInterface $reference
     * @param array                               $tree_position
     */
    public static function saveSelectedPushToPoolForField($parent_entity, $parent_field_name, $parent_field_delta, $reference, $tree_position = [])
    {
        $data = EntityStatus::accessTemporaryPushToPoolInfoForField($parent_entity->getEntityTypeId(), $parent_entity->uuid(), $parent_field_name, $parent_field_delta, $tree_position);

        // On sites that don't push, this will be NULL.
        if (empty($data['flow_id'])) {
            return;
        }

        $values = $data['pool_ids'];

        $processed = [];
        if (is_array($values)) {
            foreach ($values as $id => $selected) {
                if ($selected && 'ignore' !== $id) {
                    $processed[] = $id;
                }
            }
        } else {
            if ('ignore' !== $values) {
                $processed[] = $values;
            }
        }

        EntityStatus::saveSelectedPoolsToPushTo($reference, $data['flow_id'], $processed, $parent_entity, $parent_field_name);
    }

    /**
     * @param \Drupal\Core\Entity\EntityInterface $reference
     * @param string                              $flow_id
     * @param string[]                            $pool_ids
     * @param null|EntityInterface                $parent_entity
     * @param null|string                         $parent_field_name
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\Core\Entity\EntityStorageException
     */
    public static function saveSelectedPoolsToPushTo($reference, $flow_id, $pool_ids, $parent_entity = null, $parent_field_name = null)
    {
        $entity_type = $reference->getEntityTypeId();
        $bundle = $reference->bundle();
        $uuid = $reference->uuid();

        $flow = Flow::getAll()[$flow_id];
        $pools = Pool::getAll();

        $entity_type_pools = Pool::getSelectablePools($entity_type, $bundle, $parent_entity, $parent_field_name)[$flow_id]['pools'];
        foreach ($entity_type_pools as $entity_type_pool_id => $config) {
            $pool = $pools[$entity_type_pool_id];
            $entity_status = EntityStatus::getInfoForEntity($entity_type, $uuid, $flow, $pool);
            if (in_array($entity_type_pool_id, $pool_ids)) {
                if (!$entity_status) {
                    $entity_status = EntityStatus::create([
                        'flow' => $flow->id,
                        'pool' => $pool->id,
                        'entity_type' => $entity_type,
                        'entity_uuid' => $uuid,
                        'entity_type_version' => Flow::getEntityTypeVersion($entity_type, $bundle),
                        'flags' => 0,
                        'source_url' => null,
                    ]);
                }

                $entity_status->isPushEnabled(true);
                $entity_status->save();

                continue;
            }

            if ($entity_status) {
                $entity_status->isPushEnabled(false);
                $entity_status->save();
            }
        }

        // Also check if the entity is going to be force pushed into another pool.
        $force_push_pools = $flow->getPoolsToPushTo($reference, PushIntent::PUSH_FORCED, SyncIntent::ACTION_CREATE);
        if (count($entity_type_pools) && !count($pool_ids) && !count($force_push_pools)) {
            \Drupal::messenger()->addWarning(\Drupal::translation()->translate("You didn't assign a pool to @entity_type %entity_label so it won't be pushed along with the content.", [
                '@entity_type' => $entity_type,
                '%entity_label' => $reference->label(),
            ]));
        } elseif (count($entity_type_pools) && !count($pool_ids) && count($force_push_pools)) {
            $pools = '';
            $numItems = count($force_push_pools);
            $i = 0;
            if (count($force_push_pools) > 1) {
                foreach ($force_push_pools as $force_push_pool) {
                    if (++$i === $numItems) {
                        $pools .= $force_push_pool->label();
                    } else {
                        $pools .= $force_push_pool->label().', ';
                    }
                }
            } else {
                foreach ($force_push_pools as $force_push_pool) {
                    $pools = $force_push_pool->label();
                }
            }

            \Drupal::messenger()->addWarning(\Drupal::translation()->translate("You didn't assign a pool to @entity_type %entity_label, but it is going to be force pushed to the following pools based on the content sync configuration: %pools.", [
                '%pools' => $pools,
                '@entity_type' => $entity_type,
                '%entity_label' => $reference->label(),
            ]));
        }
    }

    /**
     * Get the entity this entity status belongs to.
     *
     * @return \Drupal\Core\Entity\EntityInterface
     */
    public function getEntity()
    {
        return \Drupal::service('entity.repository')->loadEntityByUuid(
            $this->getEntityTypeName(),
            $this->getUuid()
        );
    }

    /**
     * Returns the information if the entity has been pushed before but the last push date was reset.
     *
     * @param bool $set
     *                  Optional parameter to set the value for LastPushReset
     *
     * @return bool
     */
    public function wasLastPushReset($set = null)
    {
        if (true === $set) {
            $this->set('flags', $this->get('flags')->value | self::FLAG_LAST_PUSH_RESET);
        } elseif (false === $set) {
            $this->set('flags', $this->get('flags')->value & ~self::FLAG_LAST_PUSH_RESET);
        }

        return (bool) ($this->get('flags')->value & self::FLAG_LAST_PUSH_RESET);
    }

    /**
     * Returns the information if the entity has been pulled before but the last import date was reset.
     *
     * @param bool $set
     *                  Optional parameter to set the value for LastPullReset
     *
     * @return bool
     */
    public function wasLastPullReset($set = null)
    {
        if (true === $set) {
            $this->set('flags', $this->get('flags')->value | self::FLAG_LAST_PULL_RESET);
        } elseif (false === $set) {
            $this->set('flags', $this->get('flags')->value & ~self::FLAG_LAST_PULL_RESET);
        }

        return (bool) ($this->get('flags')->value & self::FLAG_LAST_PULL_RESET);
    }

    /**
     * Returns the information if the last push of the entity failed.
     *
     * @param bool       $set
     *                            Optional parameter to set the value for PushFailed
     * @param bool       $soft
     *                            A soft fail- this was intended according to configuration. But the user might want to know why to debug different
     *                            expectations.
     * @param null|array $details
     *                            If $set is TRUE, you can provide additional details on why the push failed. Can be gotten via
     *                            ->whyDidPushFail()
     *
     * @return bool
     */
    public function didPushFail($set = null, $soft = false, $details = null)
    {
        $flag = $soft ? self::FLAG_PUSH_FAILED_SOFT : self::FLAG_PUSH_FAILED;
        if (true === $set) {
            $this->set('flags', $this->get('flags')->value | $flag);
            if (!empty($details)) {
                $this->setData(self::DATA_PUSH_FAILURE, $details);
            }
        } elseif (false === $set) {
            $this->set('flags', $this->get('flags')->value & ~$flag);
            $this->setData(self::DATA_PUSH_FAILURE, null);
        }

        return (bool) ($this->get('flags')->value & $flag);
    }

    /**
     * Get the details provided to ->didPushFail( TRUE, ... ) before.
     *
     * @return null|array
     */
    public function whyDidPushingFail()
    {
        return $this->getData(self::DATA_PUSH_FAILURE);
    }

    /**
     * Returns the information if the last pull of the entity failed.
     *
     * @param bool       $set
     *                            Optional parameter to set the value for PullFailed
     * @param bool       $soft
     *                            A soft fail- this was intended according to configuration. But the user might want to know why to debug different
     *                            expectations.
     * @param null|array $details
     *                            If $set is TRUE, you can provide additional details on why the pull failed. Can be gotten via
     *                            ->whyDidPullFail()
     *
     * @return bool
     */
    public function didPullFail($set = null, $soft = false, $details = null)
    {
        $flag = $soft ? self::FLAG_PULL_FAILED_SOFT : self::FLAG_PULL_FAILED;
        if (true === $set) {
            $this->set('flags', $this->get('flags')->value | $flag);
            if (!empty($details)) {
                $this->setData(self::DATA_PULL_FAILURE, $details);
            }
        } elseif (false === $set) {
            $this->set('flags', $this->get('flags')->value & ~$flag);
            $this->setData(self::DATA_PULL_FAILURE, null);
        }

        return (bool) ($this->get('flags')->value & $flag);
    }

    /**
     * Get the details provided to ->didPullFail( TRUE, ... ) before.
     *
     * @return null|array
     */
    public function whyDidPullingFail()
    {
        return $this->getData(self::DATA_PULL_FAILURE);
    }

    /**
     * Returns the information if the entity has been chosen by the user to
     * be pushed with this flow and pool.
     *
     * @param bool $set
     *                            Optional parameter to set the value for PushEnabled
     * @param bool $setDependency
     *                            Optional parameter to set the value for DependencyPushEnabled
     *
     * @return bool
     */
    public function isPushEnabled($set = null, $setDependency = null)
    {
        if (true === $set) {
            $this->set('flags', $this->get('flags')->value | self::FLAG_PUSH_ENABLED);
        } elseif (false === $set) {
            $this->set('flags', $this->get('flags')->value & ~self::FLAG_PUSH_ENABLED);
        }
        if (true === $setDependency) {
            $this->set('flags', $this->get('flags')->value | self::FLAG_DEPENDENCY_PUSH_ENABLED);
        } elseif (false === $setDependency) {
            $this->set('flags', $this->get('flags')->value & ~self::FLAG_DEPENDENCY_PUSH_ENABLED);
        }

        return (bool) ($this->get('flags')->value & (self::FLAG_PUSH_ENABLED | self::FLAG_DEPENDENCY_PUSH_ENABLED));
    }

    /**
     * Returns the information if the entity has been chosen by the user to
     * be pushed with this flow and pool.
     *
     * @return bool
     */
    public function isManualPushEnabled()
    {
        return (bool) ($this->get('flags')->value & (self::FLAG_PUSH_ENABLED));
    }

    /**
     * Returns the information if the entity has been pushed with this flow and
     * pool as a dependency.
     *
     * @return bool
     */
    public function isPushedAsDependency()
    {
        return (bool) ($this->get('flags')->value & (self::FLAG_DEPENDENCY_PUSH_ENABLED));
    }

    /**
     * Returns the information if the user override the entity locally.
     *
     * @param bool $set
     *                         Optional parameter to set the value for EditOverride
     * @param bool $individual
     *
     * @return bool
     */
    public function isOverriddenLocally($set = null, $individual = false)
    {
        $status = EntityStatus::getInfosForEntity($this->getEntityTypeName(), $this->getUuid());
        if (true === $set) {
            if ($individual) {
                $this->set('flags', $this->get('flags')->value | self::FLAG_EDIT_OVERRIDE);
            } else {
                foreach ($status as $info) {
                    $info->isOverriddenLocally(true, true);
                }
            }

            return true;
        }
        if (false === $set) {
            if ($individual) {
                $this->set('flags', $this->get('flags')->value & ~self::FLAG_EDIT_OVERRIDE);
            } else {
                foreach ($status as $info) {
                    $info->isOverriddenLocally(false, true);
                }
            }

            return false;
        }

        if ($individual) {
            return (bool) ($this->get('flags')->value & self::FLAG_EDIT_OVERRIDE);
        }

        foreach ($status as $info) {
            if ($info->isOverriddenLocally(null, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the information if the entity has originally been created on this
     * site.
     *
     * @param bool  $set
     *                          Optional parameter to set the value for IsSourceEntity
     * @param mixed $individual
     *
     * @return bool
     */
    public function isSourceEntity($set = null, $individual = false)
    {
        $status = EntityStatus::getInfosForEntity($this->getEntityTypeName(), $this->getUuid());
        if (true === $set) {
            if ($individual) {
                $this->set('flags', $this->get('flags')->value | self::FLAG_IS_SOURCE_ENTITY);
            } else {
                foreach ($status as $info) {
                    $info->isSourceEntity(true, true);
                }
                $this->isSourceEntity(true, true);
            }

            return true;
        }
        if (false === $set) {
            if ($individual) {
                $this->set('flags', $this->get('flags')->value & ~self::FLAG_IS_SOURCE_ENTITY);
            } else {
                foreach ($status as $info) {
                    $info->isSourceEntity(false, true);
                }
                $this->isSourceEntity(false, true);
            }

            return false;
        }

        if ($individual) {
            return (bool) ($this->get('flags')->value & self::FLAG_IS_SOURCE_ENTITY);
        }

        foreach ($status as $info) {
            if ($info->isSourceEntity(null, true)) {
                return true;
            }
        }

        return $this->isSourceEntity(null, true);
    }

    /**
     * Returns the information if the user allowed the push.
     *
     * @param bool $set
     *                  Optional parameter to set the value for UserEnabledPush
     *
     * @return bool
     */
    public function didUserEnablePush($set = null)
    {
        if (true === $set) {
            $this->set('flags', $this->get('flags')->value | self::FLAG_USER_ENABLED_PUSH);
        } elseif (false === $set) {
            $this->set('flags', $this->get('flags')->value & ~self::FLAG_USER_ENABLED_PUSH);
        }

        return (bool) ($this->get('flags')->value & self::FLAG_USER_ENABLED_PUSH);
    }

    /**
     * Returns the information if the entity is deleted.
     *
     * @param bool $set
     *                  Optional parameter to set the value for Deleted
     *
     * @return bool
     */
    public function isDeleted($set = null)
    {
        if (true === $set) {
            $this->set('flags', $this->get('flags')->value | self::FLAG_DELETED);
        } elseif (false === $set) {
            $this->set('flags', $this->get('flags')->value & ~self::FLAG_DELETED);
        }

        return (bool) ($this->get('flags')->value & self::FLAG_DELETED);
    }

    /**
     * Returns whether the entity was pushed embedded into another parent entity.
     * This is always done for field collections but can also be enabled for other
     * entities like paragraphs or media entities. This can save a lot of requests
     * when entities aren't all syndicated individually.
     *
     * @param bool $set
     *                  Optional parameter to set the value for the flag
     *
     * @return bool
     */
    public function wasPushedEmbedded($set = null)
    {
        if (true === $set) {
            $this->set('flags', $this->get('flags')->value | self::FLAG_PUSHED_EMBEDDED);
        } elseif (false === $set) {
            $this->set('flags', $this->get('flags')->value & ~self::FLAG_PUSHED_EMBEDDED);
        }

        return (bool) ($this->get('flags')->value & self::FLAG_PUSHED_EMBEDDED);
    }

    /**
     * Returns whether the entity was pulled embedded in another parent entity.
     * This is always done for field collections but can also be enabled for other
     * entities like paragraphs or media entities. This can save a lot of requests
     * when entities aren't all syndicated individually.
     *
     * @param bool $set
     *                  Optional parameter to set the value for the flag
     *
     * @return bool
     */
    public function wasPulledEmbedded($set = null)
    {
        if (true === $set) {
            $this->set('flags', $this->get('flags')->value | self::FLAG_PULLED_EMBEDDED);
        } elseif (false === $set) {
            $this->set('flags', $this->get('flags')->value & ~self::FLAG_PULLED_EMBEDDED);
        }

        return (bool) ($this->get('flags')->value & self::FLAG_PULLED_EMBEDDED);
    }

    /**
     * If an entity is pushed or pulled embedded into another entity, we store
     * that parent entity here. This is required so that at a later point we can
     * still force pull and force push the embedded entity although it doesn't
     * exist individually.
     * This is also required to reset e.g. embedded paragraphs after the
     * "Overwrite content locally" checkbox is unchecked.
     *
     * @param string $type
     * @param string $uuid
     */
    public function setParentEntity($type, $uuid)
    {
        $this->setData(self::DATA_PARENT_ENTITY, [
            'type' => $type,
            'uuid' => $uuid,
        ]);
    }

    /**
     * See above.
     *
     * @return null|\Drupal\Core\Entity\EntityInterface
     */
    public function getParentEntity()
    {
        $parent = $this->getData(self::DATA_PARENT_ENTITY);
        if ($parent) {
            $matches = \Drupal
        ::entityTypeManager()
            ->getStorage($parent['type'])
            ->loadByProperties([
                'uuid' => $parent['uuid'],
            ]);
            if (!count($matches)) {
                return null;
            }

            return reset($matches);
        }

        return null;
    }

    /**
     * Returns the timestamp for the last pull.
     *
     * @return int
     */
    public function getLastPull()
    {
        return $this->get('last_import')->value;
    }

    /**
     * Set the last pull timestamp.
     *
     * @param int $timestamp
     */
    public function setLastPull($timestamp)
    {
        if ($this->getLastPull() == $timestamp) {
            return;
        }

        $this->set('last_import', $timestamp);

        // As this pull was successful, we can now reset the flags for status entity resets and failed pulls.
        if (!empty($timestamp)) {
            $this->wasLastPullReset(false);
            $this->didPullFail(false);

            // Delete status entities without Flow assigned- they're no longer needed.
            $error_entities = EntityStatus::getInfosForEntity($this->getEntityTypeName(), $this->getUuid(), ['flow' => self::FLOW_NO_FLOW], true);
            foreach ($error_entities as $entity) {
                $entity->delete();
            }
        }
        // Otherwise this entity has been reset.
        else {
            $this->wasLastPullReset(true);
        }
    }

    /**
     * Returns the UUID of the entity this information belongs to.
     *
     * @return string
     */
    public function getUuid()
    {
        return $this->get('entity_uuid')->value;
    }

    /**
     * Returns the entity type name of the entity this information belongs to.
     *
     * @return string
     */
    public function getEntityTypeName()
    {
        return $this->get('entity_type')->value;
    }

    /**
     * Returns the timestamp for the last push.
     *
     * @return int
     */
    public function getLastPush()
    {
        return $this->get('last_export')->value;
    }

    /**
     * Set the last pull timestamp.
     *
     * @param int $timestamp
     */
    public function setLastPush($timestamp)
    {
        if ($this->getLastPush() == $timestamp) {
            return;
        }

        $this->set('last_export', $timestamp);

        // As this push was successful, we can now reset the flags for status entity resets and failed exports.
        if (!empty($timestamp)) {
            $this->wasLastPushReset(false);
            $this->didPushFail(false);
        }
        // Otherwise this entity has been reset.
        else {
            $this->wasLastPushReset(true);
        }
    }

    /**
     * Get the flow.
     *
     * @return Flow
     */
    public function getFlow()
    {
        if (empty($this->get('flow')->value)) {
            return null;
        }

        $flows = Flow::getAll();
        if (empty($flows[$this->get('flow')->value])) {
            return null;
        }

        return $flows[$this->get('flow')->value];
    }

    /**
     * Get the pool.
     *
     * @return Pool
     */
    public function getPool()
    {
        return Pool::getAll()[$this->get('pool')->value];
    }

    /**
     * Returns the entity type version.
     *
     * @return string
     */
    public function getEntityTypeVersion()
    {
        return $this->get('entity_type_version')->value;
    }

    /**
     * Set the last pull timestamp.
     *
     * @param string $version
     */
    public function setEntityTypeVersion($version)
    {
        $this->set('entity_type_version', $version);
    }

    /**
     * Returns the entities source url.
     *
     * @return string
     */
    public function getSourceUrl()
    {
        return $this->get('source_url')->value;
    }

    /**
     * Get a previously saved key=>value pair.
     *
     * @see self::setData()
     *
     * @param null|string|string[] $key
     *                                  The key to retrieve
     *
     * @return mixed whatever you previously stored here or NULL if the key
     *               doesn't exist
     */
    public function getData($key = null)
    {
        $data = $this->get('data')->getValue();
        if (empty($data)) {
            return null;
        }

        $storage = &$data[0];

        if (empty($key)) {
            return $data;
        }

        if (!is_array($key)) {
            $key = [$key];
        }

        foreach ($key as $index) {
            if (!isset($storage[$index])) {
                return null;
            }

            $storage = &$storage[$index];
        }

        return $storage;
    }

    /**
     * Set a key=>value pair.
     *
     * @param string|string[] $key
     *                               The key to set (for hierarchical usage, provide
     *                               an array of indices
     * @param mixed           $value
     *                               The value to set. Must be a valid value for Drupal's
     *                               "map" storage (so basic types that can be serialized).
     */
    public function setData($key, $value)
    {
        $data = $this->get('data')->getValue();
        if (!empty($data)) {
            $data = $data[0];
        } else {
            $data = [];
        }
        $storage = &$data;

        if (is_string($key) && null === $value) {
            if (isset($data[$key])) {
                unset($data[$key]);
            }
        } else {
            if (!is_array($key)) {
                $key = [$key];
            }

            foreach ($key as $index) {
                if (!isset($storage[$index])) {
                    $storage[$index] = [];
                }
                $storage = &$storage[$index];
            }

            $storage = $value;
        }

        $this->set('data', $data);
    }

    /**
     * {@inheritdoc}
     */
    public static function baseFieldDefinitions(EntityTypeInterface $entity_type)
    {
        $fields = parent::baseFieldDefinitions($entity_type);

        $fields['flow'] = BaseFieldDefinition::create('string')
            ->setLabel(t('Flow'))
            ->setDescription(t('The flow the status entity is based on.'));

        $fields['pool'] = BaseFieldDefinition::create('string')
            ->setLabel(t('Pool'))
            ->setDescription(t('The pool the entity is connected to.'));

        $fields['entity_uuid'] = BaseFieldDefinition::create('string')
            ->setLabel(t('Entity UUID'))
            ->setDescription(t('The UUID of the entity that is synchronized.'))
            ->setSetting('max_length', 128);

        $fields['entity_type'] = BaseFieldDefinition::create('string')
            ->setLabel(t('Entity type'))
            ->setDescription(t('The entity type of the entity that is synchronized.'));

        $fields['entity_type_version'] = BaseFieldDefinition::create('string')
            ->setLabel(t('Entity type version'))
            ->setDescription(t('The version of the entity type provided by Content Sync.'))
            ->setSetting('max_length', 32);

        $fields['source_url'] = BaseFieldDefinition::create('string')
            ->setLabel(t('Source URL'))
            ->setDescription(t('The entities source URL.'))
            ->setRequired(false);

        $fields['last_export'] = BaseFieldDefinition::create('timestamp')
            ->setLabel(t('Last pushed'))
            ->setDescription(t('The last time the entity got pushed.'))
            ->setRequired(false);

        $fields['last_import'] = BaseFieldDefinition::create('timestamp')
            ->setLabel(t('Last pulled'))
            ->setDescription(t('The last time the entity got pulled.'))
            ->setRequired(false);

        $fields['flags'] = BaseFieldDefinition::create('integer')
            ->setLabel(t('Flags'))
            ->setDescription(t('Stores boolean information about the pushed/pulled entity.'))
            ->setSetting('unsigned', true)
            ->setDefaultValue(0);

        $fields['data'] = BaseFieldDefinition::create('map')
            ->setLabel(t('Data'))
            ->setDescription(t('Stores further information about the pushed/pulled entity that can also be used by entity and field handlers.'))
            ->setRequired(false);

        return $fields;
    }

    /**
     * @return null|string
     */
    public function getEntityPushHash()
    {
        return $this->getData(self::DATA_ENTITY_PUSH_HASH);
    }

    /**
     * @param string $hash
     */
    public function setEntityPushHash($hash)
    {
        $this->setData(self::DATA_ENTITY_PUSH_HASH, $hash);
    }
}
