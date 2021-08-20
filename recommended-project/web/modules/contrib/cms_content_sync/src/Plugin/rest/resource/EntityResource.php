<?php

namespace Drupal\cms_content_sync\Plugin\rest\resource;

use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\cms_content_sync\Exception\SyncException;
use Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\SyncCoreInterface\DrupalApplication;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfo;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Renderer;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides entity interfaces for Content Sync, allowing Sync Core to
 * request and manipulate entities.
 *
 * @RestResource(
 *   id = "cms_content_sync_entity_resource",
 *   label = @Translation("Content Sync - Legacy entity resource"),
 *   uri_paths = {
 *     "canonical" = "/rest/cms-content-sync/{api}/{entity_type}/{entity_bundle}/{entity_type_version}/{entity_uuid}",
 *     "create" = "/rest/cms-content-sync/{api}/{entity_type}/{entity_bundle}/{entity_type_version}"
 *   }
 * )
 */
class EntityResource extends ResourceBase
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
     * @var string TYPE_HAS_NOT_BEEN_FOUND
     *             The entity type doesn't exist or can't be accessed
     */
    public const TYPE_HAS_NOT_BEEN_FOUND = 'The entity type has not been found.';

    /**
     * @var string TYPE_HAS_INCOMPATIBLE_VERSION The version hashes are different
     */
    public const TYPE_HAS_INCOMPATIBLE_VERSION = 'The entity type has an incompatible version.';

    /**
     * @var string READ_LIST_ENTITY_ID
     *             "ID" used to perform list requests in the
     *             {@see EntityResource}. Should be refactored later.
     */
    public const READ_LIST_ENTITY_ID = '0';

    /**
     * @var string PING_PARAMETER
     */
    public const PING_PARAMETER = 'ping';

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

    /**
     * @param string $method
     *
     * @return string a URL the Sync Core can use to ping this site and check if all methods work (so inbound traffic
     *                is allowed from the Sync Core)
     */
    public static function getInternalPingUrl($method)
    {
        return DrupalApplication::get()->getRestUrl(
            self::PING_PARAMETER,
            self::PING_PARAMETER,
            self::PING_PARAMETER,
            self::PING_PARAMETER,
            'POST' === $method ? null : self::PING_PARAMETER
        );
    }

    /**
     * Responds to entity GET requests.
     *
     * @param string $entity_type
     *                              The name of an entity type
     * @param string $entity_bundle
     *                              The name of an entity bundle
     * @param string $entity_uuid
     *                              The uuid of an entity
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     *
     * @return \Symfony\Component\HttpFoundation\Response
     *                                                    A list of entities of the given type and bundle
     */
    public function get($entity_type, $entity_bundle, $entity_uuid)
    {
        return new ResourceResponse(
            ['message' => t(self::TYPE_HAS_NOT_BEEN_FOUND)->render()],
            self::CODE_NOT_FOUND
        );
    }

    /**
     * Responds to entity PATCH requests.
     *
     * @param string $api
     *                                    The used content sync api
     * @param string $entity_type
     *                                    The name of an entity type
     * @param string $entity_bundle
     *                                    The name of an entity bundle
     * @param string $entity_type_version
     *                                    The version of the entity type to compare ours against
     * @param string $entity_uuid
     *                                    The uuid of an entity
     * @param array  $data
     *                                    The data to be stored in the entity
     *
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     *
     * @return \Symfony\Component\HttpFoundation\Response
     *                                                    A list of entities of the given type and bundle
     */
    public function patch($api, $entity_type, $entity_bundle, $entity_type_version, $entity_uuid, array $data)
    {
        return $this->handleIncomingEntity($api, $entity_type, $entity_bundle, $entity_type_version, $data, SyncIntent::ACTION_UPDATE);
    }

    /**
     * Responds to entity DELETE requests.
     *
     * @param string $api
     *                                    The used content sync api
     * @param string $entity_type
     *                                    The name of an entity type
     * @param string $entity_bundle
     *                                    The name of an entity bundle
     * @param string $entity_type_version
     *                                    The version of the entity type
     * @param string $entity_uuid
     *                                    The uuid of an entity
     *
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     *
     * @return \Symfony\Component\HttpFoundation\Response
     *                                                    A list of entities of the given type and bundle
     */
    public function delete($api, $entity_type, $entity_bundle, $entity_type_version, $entity_uuid)
    {
        return $this->handleIncomingEntity($api, $entity_type, $entity_bundle, $entity_type_version, ['uuid' => $entity_uuid, 'id' => $entity_uuid], SyncIntent::ACTION_DELETE);
    }

    /**
     * Responds to entity POST requests.
     *
     * @param string $api
     *                                    The used content sync api
     * @param string $entity_type
     *                                    The posted entity type
     * @param string $entity_bundle
     *                                    The name of an entity bundle
     * @param string $entity_type_version
     *                                    The version of the entity type
     * @param array  $data
     *                                    The data to be stored in the entity
     *
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     *
     * @return \Symfony\Component\HttpFoundation\Response
     *                                                    A list of entities of the given type and bundle
     */
    public function post($api, $entity_type, $entity_bundle, $entity_type_version, array $data)
    {
        return $this->handleIncomingEntity($api, $entity_type, $entity_bundle, $entity_type_version, $data, SyncIntent::ACTION_CREATE);
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

    /**
     * @param string $api
     *                                    The API {@see Flow}
     * @param string $entity_type_name
     *                                    The entity type of the processed entity
     * @param string $entity_bundle
     *                                    The bundle of the processed entity
     * @param string $entity_type_version
     *                                    The version the config was saved for
     * @param array  $data
     *                                    For {@see ::ACTION_CREATE} and
     *                                    {@see ::ACTION_UPDATE}: the data for the entity. Will
     *                                    be passed to {@see SyncIntent}.
     * @param string $action
     *                                    The {@see ::ACTION_*} to be performed on the entity
     *
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\Core\Entity\EntityStorageException
     *
     * @return \Symfony\Component\HttpFoundation\Response the result (error,
     *                                                    ignorance or success)
     */
    private function handleIncomingEntity($api, $entity_type_name, $entity_bundle, $entity_type_version, array $data, $action)
    {
        if (self::PING_PARAMETER === $api) {
            return new ResourceResponse(SyncIntent::ACTION_DELETE === $action ? null : ['pong' => true]);
        }

        $entity_types = $this->entityTypeBundleInfo->getAllBundleInfo();

        if (empty($entity_types[$entity_type_name])) {
            return new ResourceResponse(
                SyncIntent::ACTION_DELETE == $action ? null : ['message' => t(self::TYPE_HAS_NOT_BEEN_FOUND)->render()],
                self::CODE_NOT_FOUND
            );
        }

        $is_dependency = isset($_GET['is_dependency']) && 'true' == $_GET['is_dependency'];
        $is_manual = isset($_GET['is_manual']) && 'true' == $_GET['is_manual'];
        $reason = $is_dependency ? PullIntent::PULL_AS_DEPENDENCY :
      ($is_manual ? PullIntent::PULL_MANUALLY : PullIntent::PULL_AUTOMATICALLY);

        $entity_uuid = null;

        $pool = Pool::getAll()[$api];
        if (empty($pool)) {
            \Drupal::logger('cms_content_sync')->warning('@not PULL @action @entity_type:@bundle @uuid @reason: @message<br>Flow: @flow_id', [
                '@reason' => $reason,
                '@action' => $action,
                '@entity_type' => $entity_type_name,
                '@bundle' => $entity_bundle,
                '@uuid' => $entity_uuid,
                '@not' => 'NO',
                '@flow_id' => $api,
                '@message' => t('No pool config matches this request (@api).', [
                    '@api' => $api,
                ])->render(),
            ]);

            $this->saveFailedPull(
                $api,
                $entity_type_name,
                $entity_bundle,
                $entity_type_version,
                $entity_uuid,
                PullIntent::PULL_FAILED_UNKNOWN_POOL,
                $action,
                $reason
            );

            return new ResourceResponse(
                SyncIntent::ACTION_DELETE == $action ? null : ['message' => t(self::TYPE_HAS_NOT_BEEN_FOUND)->render()],
                self::CODE_NOT_FOUND
            );
        }

        $flow = Flow::getFlowForApiAndEntityType($pool, $entity_type_name, $entity_bundle, $reason, $action);

        // Deletion requests will not provide the "is_dependency" query parameter.
        if (empty($flow) && SyncIntent::ACTION_DELETE == $action && PullIntent::PULL_AS_DEPENDENCY != $reason) {
            $flow = Flow::getFlowForApiAndEntityType($pool, $entity_type_name, $entity_bundle, PullIntent::PULL_AS_DEPENDENCY, $action);
            if (!empty($flow)) {
                $reason = PullIntent::PULL_AS_DEPENDENCY;
            }
        }

        if (empty($flow)) {
            \Drupal::logger('cms_content_sync')->notice('@not PULL @action @entity_type:@bundle @uuid @reason: @message', [
                '@reason' => $reason,
                '@action' => $action,
                '@entity_type' => $entity_type_name,
                '@bundle' => $entity_bundle,
                '@uuid' => $entity_uuid,
                '@not' => 'NO',
                '@message' => t('No synchronization config matches this request (dependency: @dependency, manual: @manual).', [
                    '@dependency' => $is_dependency ? 'YES' : 'NO',
                    '@manual' => $is_manual ? 'YES' : 'NO',
                ])->render(),
            ]);

            $this->saveFailedPull(
                $api,
                $entity_type_name,
                $entity_bundle,
                $entity_type_version,
                $entity_uuid,
                PullIntent::PULL_FAILED_NO_FLOW,
                $action,
                $reason
            );

            return new ResourceResponse(
                SyncIntent::ACTION_DELETE == $action ? null : ['message' => t(self::TYPE_HAS_NOT_BEEN_FOUND)->render()],
                self::CODE_NOT_FOUND
            );
        }

        $operation = $pool
            ->getClient()
            ->getSyndicationService()
            ->handlePull($flow->id, $entity_type_name, $entity_bundle, $data, SyncIntent::ACTION_DELETE == $action);

        // DELETE requests only give the ID of the config, not their UUID. So we need to grab the UUID from our local
        // database before continuing.
        if (SyncIntent::ACTION_DELETE === $action && EntityHandlerPluginManager::isEntityTypeConfiguration($entity_type_name)) {
            $entity_uuid = \Drupal::entityTypeManager()
                ->getStorage($entity_type_name)
                ->load($operation->getId())
                ->uuid();
        } else {
            $entity_uuid = $operation->getUuid();
        }

        $local_version = Flow::getEntityTypeVersion($entity_type_name, $entity_bundle);

        // Allow DELETE requests- when an entity is deleted, the entity type definition may have changed in the meantime
        // but this doesn't prevent us from deleting it. The version is only important for creations and updates.
        if ($entity_type_version != $local_version && SyncIntent::ACTION_DELETE != $action) {
            \Drupal::logger('cms_content_sync')->warning('@not PULL @action @entity_type:@bundle @uuid @reason: @message<br>Flow: @flow_id | Pool: @pool_id', [
                '@reason' => $reason,
                '@action' => $action,
                '@entity_type' => $entity_type_name,
                '@bundle' => $entity_bundle,
                '@uuid' => $entity_uuid,
                '@not' => 'NO',
                '@flow_id' => $api,
                '@pool_id' => $pool->id(),
                '@message' => t('The requested entity type version @requested doesn\'t match the local entity type version @local.', [
                    '@requested' => $entity_type_version,
                    '@local' => $local_version,
                ])->render(),
            ]);

            $this->saveFailedPull(
                $api,
                $entity_type_name,
                $entity_bundle,
                $entity_type_version,
                $entity_uuid,
                PullIntent::PULL_FAILED_DIFFERENT_VERSION,
                $action,
                $reason,
                $flow->id
            );

            return new ResourceResponse(
                ['message' => t(self::TYPE_HAS_INCOMPATIBLE_VERSION)->render()],
                self::CODE_NOT_FOUND
            );
        }

        try {
            $intent = new PullIntent($flow, $pool, $reason, $action, $entity_type_name, $entity_bundle, $operation);
            $status = $intent->execute();
        } catch (SyncException $e) {
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
                '@flow_id' => $api,
                '@pool_id' => $pool->id(),
                '@message' => $message,
                '@trace' => ($e->parentException ? $e->parentException->getTraceAsString()."\n\n\n" : '').$e->getTraceAsString(),
                '@request_body' => json_encode($data),
            ]);

            $this->saveFailedPull(
                $api,
                $entity_type_name,
                $entity_bundle,
                $entity_type_version,
                $entity_uuid,
                PullIntent::PULL_FAILED_CONTENT_SYNC_ERROR,
                $action,
                $reason,
                $flow->id
            );

            return new ResourceResponse(
                SyncIntent::ACTION_DELETE == $action ? null : [
                    'message' => t(
                        'SyncException @code: @message',
                        [
                            '@code' => $e->errorCode,
                            '@message' => $e->getMessage(),
                        ]
                    )->render(),
                    'code' => $e->errorCode,
                ],
                500
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
                '@flow_id' => $api,
                '@pool_id' => $pool->id(),
                '@message' => $message,
                '@trace' => $e->getTraceAsString(),
                '@request_body' => json_encode($data),
            ]);

            $this->saveFailedPull(
                $api,
                $entity_type_name,
                $entity_bundle,
                $entity_type_version,
                $entity_uuid,
                PullIntent::PULL_FAILED_INTERNAL_ERROR,
                $action,
                $reason,
                $flow->id
            );

            return new ResourceResponse(
                SyncIntent::ACTION_DELETE == $action ? null : [
                    'message' => t('Unexpected error: @message', ['@message' => $e->getMessage()])->render(),
                ],
                500
            );
        }

        if (!$status) {
            $this->saveFailedPull(
                $api,
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
            $entity = $intent->getEntity();
            $url = null;
            if ($entity && $entity->hasLinkTemplate('canonical')) {
                try {
                    $url = $entity->toUrl('canonical', ['absolute' => true])
                        ->toString(true)
                        ->getGeneratedUrl();
                } catch (\Exception $e) {
                    throw new SyncException(SyncException::CODE_UNEXPECTED_EXCEPTION, $e);
                }
            }

            $response_body = $operation->getResponseBody($url);

            // If we send data for DELETE requests, the Drupal Serializer will throw
            // a random error. So we just leave the body empty then.
            return new ModifiedResourceResponse(SyncIntent::ACTION_DELETE == $action ? null : $response_body);
        }

        return new ResourceResponse(
            SyncIntent::ACTION_DELETE == $action ? null : [
                'message' => t('Entity is not configured to be pulled yet.')->render(),
            ],
            404
        );
    }
}
