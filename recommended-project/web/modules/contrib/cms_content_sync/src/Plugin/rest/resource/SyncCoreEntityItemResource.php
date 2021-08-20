<?php

namespace Drupal\cms_content_sync\Plugin\rest\resource;

use Drupal\cms_content_sync\Controller\Migration;
use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\cms_content_sync\Exception\SyncException;
use Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncCoreInterface\SyncCoreFactory;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfo;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Renderer;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use EdgeBox\SyncCore\V2\Syndication\PushSingle;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides entity interfaces for Content Sync, allowing Sync Core v2 to
 * request and manipulate entities.
 *
 * @RestResource(
 *   id = "cms_content_sync_sync_core_entity_item",
 *   label = @Translation("Content Sync: Sync Core: Entity item"),
 *   uri_paths = {
 *     "canonical" = "/rest/cms-content-sync/v2/{flow_id}/{entity_type}/{entity_bundle}/{shared_entity_id}",
 *     "create" = "/rest/cms-content-sync/v2/{flow_id}/{entity_type}/{entity_bundle}/{shared_entity_id}"
 *   }
 * )
 */
class SyncCoreEntityItemResource extends ResourceBase
{
    /**
     * @var int CODE_INVALID_DATA The provided data could not be interpreted
     */
    public const CODE_INVALID_DATA = 401;

    /**
     * @var int CODE_NOT_FOUND The entity doesn't exist or can't be accessed
     */
    public const CODE_NOT_FOUND = 404;

    /**
     * @var \Drupal\Core\Entity\EntityTypeBundleInfo
     */
    protected $entityTypeBundleInfo;

    /**
     * @var \Drupal\Core\Entity\EntityTypeManager
     */
    protected $entityTypeManager;

    /**
     * @var \Drupal\Core\Render\Renderer
     */
    protected $renderedManager;

    /**
     * @var \Drupal\Core\Entity\EntityRepositoryInterface
     */
    protected $entityRepository;

    /**
     * Constructs an object.
     *
     * @param array                                          $configuration
     *                                                                                A configuration array containing information about the plugin instance
     * @param string                                         $plugin_id
     *                                                                                The plugin_id for the plugin instance
     * @param mixed                                          $plugin_definition
     *                                                                                The plugin implementation definition
     * @param array                                          $serializer_formats
     *                                                                                The available serialization formats
     * @param \Psr\Log\LoggerInterface                       $logger
     *                                                                                A logger instance
     * @param \Drupal\Core\Entity\EntityTypeBundleInfo       $entity_type_bundle_info
     *                                                                                An entity type bundle info instance
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
     *                                                                                An entity type manager instance
     * @param \Drupal\Core\Render\Renderer                   $render_manager
     *                                                                                A rendered instance
     * @param \Drupal\Core\Entity\EntityRepositoryInterface  $entity_repository
     *                                                                                The entity repository interface
     */
    public function __construct(
        array $configuration,
        $plugin_id,
        $plugin_definition,
        array $serializer_formats,
        LoggerInterface $logger,
        EntityTypeBundleInfo $entity_type_bundle_info,
        EntityTypeManagerInterface $entity_type_manager,
        Renderer $render_manager,
        EntityRepositoryInterface $entity_repository
    ) {
        parent::__construct(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $serializer_formats,
            $logger
        );

        $this->entityTypeBundleInfo = $entity_type_bundle_info;
        $this->entityTypeManager = $entity_type_manager;
        $this->renderedManager = $render_manager;
        $this->entityRepository = $entity_repository;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(
        ContainerInterface $container,
        array $configuration,
        $plugin_id,
        $plugin_definition
    ) {
        return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('entity.repository')
    );
    }

    public function get($flow_id, $entity_type, $entity_bundle, $shared_entity_id)
    {
        $flow = Flow::getAll()[$flow_id];
        if (empty($flow)) {
            $message = t("The flow @flow_id doesn't exist.", ['@flow_id' => $flow_id])->render();
            \Drupal::logger('cms_content_sync')->notice('@not GET @shared_entity_id: @message', [
                '@shared_entity_id' => $shared_entity_id,
                '@not' => 'NO',
                '@message' => $message,
            ]);

            return $this->respondWith(
                ['message' => $message],
                self::CODE_NOT_FOUND
            );
        }

        $infos = EntityStatus::getInfosForEntity($entity_type, $shared_entity_id, ['flow' => $flow_id]);
        foreach ($infos as $info) {
            if ($info->isDeleted()) {
                return $this->respondWith(
                    ['message' => 'This entity has been deleted.'],
                    self::CODE_NOT_FOUND
                );
            }
        }

        $entity = \Drupal::service('entity.repository')->loadEntityByUuid(
            $entity_type,
            $shared_entity_id
        );
        if (!$entity) {
            return $this->respondWith(
                ['message' => 'This entity does not exist.'],
                self::CODE_NOT_FOUND
            );
        }

        $always_v2 = Migration::alwaysUseV2();

        if (!$always_v2) {
            Migration::useV2(true);
        }
        /**
         * @var PushIntent $intent
         */
        $intent = PushIntent::pushEntity($entity, PushIntent::PUSH_ANY, SyncIntent::ACTION_CREATE, $flow, null, true);

        if (!$intent) {
            return $this->respondWith(
                ['message' => 'This entity is not configured to be pushed.'],
                self::CODE_NOT_FOUND
            );
        }

        /**
         * @var PushSingle $operation
         */
        $operation = $intent->getOperation();
        $body = $operation->getData();

        $intent->afterPush(SyncIntent::ACTION_CREATE, $entity);

        if (!$always_v2) {
            Migration::useV2(false);
        }

        return $this->respondWith(
            json_decode(json_encode($body), true),
            200
        );
    }

    public function delete($flow_id, $entity_type, $entity_bundle, $shared_entity_id)
    {
        return $this->handleIncomingEntity($flow_id, $entity_type, $entity_bundle, $shared_entity_id, json_decode(file_get_contents('php://input'), true), SyncIntent::ACTION_DELETE);
    }

    public function post($flow_id, $entity_type, $entity_bundle, $shared_entity_id, array $data)
    {
        return $this->handleIncomingEntity($flow_id, $entity_type, $entity_bundle, $shared_entity_id, $data, SyncIntent::ACTION_CREATE);
    }

    protected function respondWith($body, $status, $serialize = false)
    {
        $response = new ModifiedResourceResponse(
            $serialize ? null : $body,
            $status
        );
        if ($serialize) {
            $response->setContent(
                json_encode($body)
            );
        }

        return $response;
    }

    /**
     * Save that the pull for the given entity failed.
     *
     * @param string $pool_id
     *                        The Pool ID
     * @param $entity_type
     *   The Entity Type ID
     * @param $entity_bundle
     *   The bundle name
     * @param $entity_type_version
     *   The requested entity type version
     * @param $entity_uuid
     *   The entity UUID
     * @param $failure_reason
     * @param $action
     * @param $reason
     * @param null $flow_id
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\Core\Entity\EntityStorageException
     */
    protected function saveFailedPull($pool_id, $entity_type, $entity_bundle, $entity_type_version, $entity_uuid, $failure_reason, $action, $reason, $flow_id = null)
    {
        $entity_status = EntityStatus::getInfoForEntity($entity_type, $entity_uuid, $flow_id, $pool_id);

        if (!$entity_status) {
            $entity_status = EntityStatus::create([
                'flow' => $flow_id ? $flow_id : EntityStatus::FLOW_NO_FLOW,
                'pool' => $pool_id,
                'entity_type' => $entity_type,
                'entity_uuid' => $entity_uuid,
                'entity_type_version' => $entity_type_version,
                'flags' => 0,
                'source_url' => null,
            ]);
        }

        $soft_fails = [
            PullIntent::PULL_FAILED_UNKNOWN_POOL,
            PullIntent::PULL_FAILED_NO_FLOW,
            PullIntent::PULL_FAILED_HANDLER_DENIED,
        ];

        $soft = in_array($failure_reason, $soft_fails);

        $entity_status->didPullFail(true, $soft, [
            'error' => $failure_reason,
            'action' => $action,
            'reason' => $reason,
            'bundle' => $entity_bundle,
        ]);

        $entity_status->save();
    }

    private function handleIncomingEntity($flow_id, $entity_type_name, $entity_bundle, $shared_entity_id, array $data, $action)
    {
        $flow = Flow::getAll()[$flow_id];
        if (empty($flow)) {
            $message = t("The flow @flow_id doesn't exist.", ['@flow_id' => $flow_id])->render();
            \Drupal::logger('cms_content_sync')->notice('@not PULL @action @shared_entity_id: @message', [
                '@action' => $action,
                '@shared_entity_id' => $shared_entity_id,
                '@not' => 'NO',
                '@message' => $message,
            ]);

            return $this->respondWith(
                ['message' => $message],
                self::CODE_NOT_FOUND,
                SyncIntent::ACTION_DELETE == $action
            );
        }

        \Drupal::logger('cms_content_sync')->notice('received @shared_entity_id via @flow_id with @body', ['@shared_entity_id' => $shared_entity_id, '@flow_id' => $flow_id, '@body' => json_encode($data, JSON_PRETTY_PRINT)]);

        $reason = PullIntent::PULL_FORCED;

        $core = SyncCoreFactory::getSyncCoreV2();
        $all_pools = Pool::getAll();
        $pools = [];
        $operation = $core
            ->getSyndicationService()
            ->handlePull($flow->id, null, null, $data, SyncIntent::ACTION_DELETE === $action);

        $entity_type_name = $operation->getEntityTypeNamespaceMachineName();
        $entity_bundle = $operation->getEntityTypeMachineName();
        $entity_type_version = $operation->getEntityTypeVersionId();

        if (EntityHandlerPluginManager::isEntityTypeConfiguration($entity_type_name)) {
            $entity_uuid = \Drupal::entityTypeManager()
                ->getStorage($entity_type_name)
                ->load($operation->getId())
                ->uuid();
        } else {
            $entity_uuid = $shared_entity_id;
        }

        // Delete doesn't come with pools
        $pool_machine_names = $operation->getPoolIds();
        if (empty($pool_machine_names)) {
            $pool_machine_names = [];
            $statuses = EntityStatus::getInfosForEntity($operation->getEntityTypeNamespaceMachineName(), $entity_uuid, [
                'flow' => $flow_id,
            ]);
            // Maybe the entity type is overloaded (multiple Flows for the same type) and the Sync Core uses a
            // different Flow for the delete request because none of the Flows matches.
            if (empty($statuses)) {
                $statuses = EntityStatus::getInfosForEntity($operation->getEntityTypeNamespaceMachineName(), $entity_uuid);
            }
            foreach ($statuses as $status) {
                $pool_machine_names[] = $status->getPool()->id();
            }
            \Drupal::logger('cms_content_sync')->notice(json_encode([
                $operation->getEntityTypeNamespaceMachineName(),
                $entity_uuid,
                $flow_id,
                count($statuses),
                $pool_machine_names,
            ]));
        }

        // TODO: Handle multiple Pools at once.
        foreach ($pool_machine_names as $machine_name) {
            if (!isset($all_pools[$machine_name])) {
                $message = t("The pool @machine_name doesn't exist.", ['@machine_name' => $machine_name])->render();
                \Drupal::logger('cms_content_sync')->notice('@not PULL @action @shared_entity_id: @message', [
                    '@action' => $action,
                    '@shared_entity_id' => $shared_entity_id,
                    '@not' => 'NO',
                    '@message' => $message,
                ]);

                $this->saveFailedPull(
                    $machine_name,
                    $entity_type_name,
                    $entity_bundle,
                    $entity_type_version,
                    $entity_uuid,
                    PullIntent::PULL_FAILED_UNKNOWN_POOL,
                    $action,
                    $reason
                );

                return $this->respondWith(
                    ['message' => $message],
                    self::CODE_NOT_FOUND,
                    SyncIntent::ACTION_DELETE == $action
                );
            }

            $pools[] = $all_pools[$machine_name];
        }

        if (empty($pools)) {
            return $this->respondWith(['message' => "No pools were given and the entity doesn't exist on this site with any pool."], 404, SyncIntent::ACTION_DELETE == $action);
        }

        try {
            $intent = new PullIntent($flow, $pools[0], $reason, $action, $entity_type_name, $entity_bundle, $operation);
            $status = $intent->execute();

            $parent = $intent->getEntity();
            $parent_type = $parent ? $parent->getEntityTypeId() : null;
            $parent_uuid = $parent ? $parent->uuid() : null;

            while ($embed = $operation->getNextUnprocessedEmbed()) {
                $embed_pool = null;
                foreach ($embed->getPoolIds() as $pool_id) {
                    if (isset($all_pools[$pool_id])) {
                        $embed_pool = $all_pools[$pool_id];

                        break;
                    }
                }
                if (!$embed_pool) {
                    continue;
                }
                $embed_intent = new PullIntent($flow, $embed_pool, $reason, $action, $embed->getEntityTypeNamespaceMachineName(), $embed->getEntityTypeMachineName(), $embed, $parent_type, $parent_uuid);
                $embed_intent->execute();
            }

            // Delete menu items that no longer exist.
            if ($parent) {
                $menu_link_manager = \Drupal::service('plugin.manager.menu.link');
                /**
                 * @var Drupal\menu_link_content\Plugin\Menu\MenuLinkContent[] $menu_items
                 */
                $menu_items = $menu_link_manager->loadLinksByRoute('entity.'.$parent_type.'.canonical', [$parent_type => $parent->id()]);
                foreach ($menu_items as $plugin_item) {
                    /**
                     * @var Drupal\menu_link_content\Entity\MenuLinkContent $item
                     */
                    // We need to get an Entity at this point,
                    // but 'getEntity' is protected for some reason.
                    // So we don't have other choice here but use a reflection.
                    $menu_link_reflection = new \ReflectionMethod('\Drupal\menu_link_content\Plugin\Menu\MenuLinkContent', 'getEntity');
                    $menu_link_reflection->setAccessible(true);
                    $item = $menu_link_reflection->invoke($plugin_item, 'getEntity');

                    if (!$operation->isEmbedded($item->getEntityTypeId(), $item->uuid())) {
                        $menu_pools = [];
                        $statuses = EntityStatus::getInfosForEntity($item->getEntityTypeId(), $item->uuid(), [
                            'flow' => $flow_id,
                        ]);
                        foreach ($statuses as $status) {
                            if (!$status->getLastPull() || !$status->wasPulledEmbedded()) {
                                continue;
                            }
                            $menu_pools[] = $status->getPool();
                        }
                        if (empty($menu_pools)) {
                            continue;
                        }
                        $menu_operation = new class($item) {
                            /**
                             * @var Drupal\menu_link_content\Entity\MenuLinkContent
                             */
                            protected $item;

                            public function __construct($item)
                            {
                                $this->item = $item;
                            }

                            public function getUuid()
                            {
                                return $this->item->uuid();
                            }

                            public function getSourceUrl()
                            {
                                return '';
                            }

                            public function getUsedTranslationLanguages()
                            {
                                return [];
                            }

                            public function getName()
                            {
                                return $this->item->label();
                            }

                            public function getProperty($name)
                            {
                                try {
                                    return $this->item->get($name)->getValue();
                                } catch (\Exception $e) {
                                    return null;
                                }
                            }
                        };
                        $menu_intent = new PullIntent($flow, $menu_pools[0], PullIntent::PULL_FORCED, SyncIntent::ACTION_DELETE, $item->getEntityTypeId(), $item->bundle(), $menu_operation, $parent_type, $parent_uuid);
                        $menu_intent->execute();
                    }
                }
            }

            if (!Migration::alwaysUseV2()) {
                Migration::entityUsedV2($flow->id, $entity_type_name, $entity_bundle, $entity_uuid, EntityHandlerPluginManager::isEntityTypeConfiguration($entity_type_name) ? $shared_entity_id : null, false);
            }
        }
        // TODO: Log explicitly if this was due to an embedded entity.
        catch (SyncException $e) {
            $message = $e->parentException ? $e->parentException->getMessage() : (
                $e->errorCode == $e->getMessage() ? '' : $e->getMessage()
            );
            if ($message) {
                $message = t('Internal error @code: @message', [
                    '@code' => $e->errorCode,
                    '@message' => $message,
                ])->render();
            } else {
                $message = t('Internal error @code', [
                    '@code' => $e->errorCode,
                ])->render();
            }

            \Drupal::logger('cms_content_sync')->error('@not PULL @action @entity_type:@bundle @uuid @reason: @message'."\n".'@trace'."\n".'@request_body<br>Flow: @flow_id | Pool: @pool_id', [
                '@reason' => $reason,
                '@action' => $action,
                '@entity_type' => $entity_type_name,
                '@bundle' => $entity_bundle,
                '@uuid' => $entity_uuid,
                '@not' => 'NO',
                '@flow_id' => $flow_id,
                '@pool_id' => $pools[0]->id(),
                '@message' => $message,
                '@trace' => ($e->parentException ? $e->parentException->getTraceAsString()."\n\n\n" : '').$e->getTraceAsString(),
                '@request_body' => json_encode($data),
            ]);

            $this->saveFailedPull(
                $pools[0]->id(),
                $entity_type_name,
                $entity_bundle,
                $entity_type_version,
                $entity_uuid,
                PullIntent::PULL_FAILED_CONTENT_SYNC_ERROR,
                $action,
                $reason,
                $flow->id
            );

            return $this->respondWith(
                [
                    'message' => t(
                        'SyncException @code: @message',
                        [
                            '@code' => $e->errorCode,
                            '@message' => $e->getMessage(),
                        ]
                    )->render(),
                    'code' => $e->errorCode,
                ],
                500,
                SyncIntent::ACTION_DELETE == $action
            );
        } catch (\Exception $e) {
            $message = $e->getMessage();

            \Drupal::logger('cms_content_sync')->error('@not PULL @action @entity_type:@bundle @uuid @reason: @message'."\n".'@trace'."\n".'@request_body<br>Flow: @flow_id | Pool: @pool_id', [
                '@reason' => $reason,
                '@action' => $action,
                '@entity_type' => $entity_type_name,
                '@bundle' => $entity_bundle,
                '@uuid' => $entity_uuid,
                '@not' => 'NO',
                '@flow_id' => $flow_id,
                '@pool_id' => $pools[0]->id(),
                '@message' => $message,
                '@trace' => $e->getTraceAsString(),
                '@request_body' => json_encode($data),
            ]);

            $this->saveFailedPull(
                $pools[0]->id,
                $entity_type_name,
                $entity_bundle,
                $entity_type_version,
                $entity_uuid,
                PullIntent::PULL_FAILED_INTERNAL_ERROR,
                $action,
                $reason,
                $flow->id
            );

            return $this->respondWith(
                [
                    'message' => t('Unexpected error: @message', ['@message' => $e->getMessage()])->render(),
                ],
                500,
                SyncIntent::ACTION_DELETE == $action
            );
        }

        if (!$status) {
            $this->saveFailedPull(
                $pools[0]->id,
                $entity_type_name,
                $entity_bundle,
                $entity_type_version,
                $entity_uuid,
                PullIntent::PULL_FAILED_HANDLER_DENIED,
                $action,
                $reason,
                $flow->id
            );
        }

        if ($status) {
            $url = SyncIntent::ACTION_DELETE === $action ? null : $intent->getViewUrl();

            $response_body = $operation->getResponseBody($url);

            // If we send data for DELETE requests, the Drupal Serializer will throw
            // a random error. So we just leave the body empty then.
            return $this->respondWith($response_body, 200, SyncIntent::ACTION_DELETE == $action);
        }

        return $this->respondWith(
            [
                'message' => t('Entity is not configured to be pulled yet.')->render(),
            ],
            404,
            SyncIntent::ACTION_DELETE == $action
        );
    }
}
