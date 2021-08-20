<?php

namespace Drupal\cms_content_sync\Plugin\rest\resource;

use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager;
use Drupal\cms_content_sync\PullIntent;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfo;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Renderer;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use EdgeBox\SyncCore\Exception\SyncCoreException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides entity interfaces for Content Sync, allowing the manual
 * pull dashboard to query preview entities and to pull them to the site.
 *
 * @RestResource(
 *   id = "cms_content_sync_import_entity",
 *   label = @Translation("Content Sync Pull"),
 *   uri_paths = {
 *     "canonical" = "/rest/cms-content-sync-import/{pool_id}",
 *     "create" = "/rest/cms-content-sync-import/{pool_id}/{entity_type_name}/{bundle_name}/{shared_entity_id}"
 *   }
 * )
 */
class PullEntity extends ResourceBase
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
     * @var int CODE_UNEXPECTED_ERROR The Sync Core wasn't able to synchronize this entity
     */
    public const CODE_UNEXPECTED_ERROR = 500;

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
     * Responds to entity GET requests.
     *
     * @param string $pool_id
     *                        The ID of the selected flow
     *
     * @throws \Exception
     *
     * @return \Symfony\Component\HttpFoundation\Response
     *                                                    A list of entities of the given type and bundle
     */
    public function get(string $pool_id)
    {
        $cache_build = [
            '#cache' => [
                'max-age' => 0,
            ],
        ];

        // Loaded directly from the Sync Core, so we just need to provide the status for them.
        if (!empty($_GET['entities'])) {
            $items = [];

            $types = explode(';', $_GET['entities']);
            foreach ($types as $type) {
                list($type, $ids) = explode(':', $type);
                $ids = explode(',', $ids);
                foreach ($ids as $id) {
                    list(, , $entity_type_name, $bundle_name) = explode('-', $type);
                    $items[] = array_merge(
                        [
                            'entity_type_id' => $type,
                            'id' => $id,
                        ],
                        $this->getPreviewItemData(
                            $entity_type_name,
                            $bundle_name,
                            $id
                        )
                    );
                }
            }

            $resource_response = new ResourceResponse([
                'items' => $items,
            ]);
            $resource_response->addCacheableDependency($cache_build);

            return $resource_response;
        }

        $pool = Pool::getAll()[$pool_id];

        if (!$pool) {
            $resource_response = new ResourceResponse(['message' => "Unknown pool {$pool_id}."], self::CODE_NOT_FOUND);
            $resource_response->addCacheableDependency($cache_build);

            return $resource_response;
        }

        $entity_type_ids = [];
        $entity_type_name = isset($_GET['entity_type_name']) ? $_GET['entity_type_name'] : null;
        $bundle_name = isset($_GET['bundle_name']) ? $_GET['bundle_name'] : null;
        $page = isset($_GET['page']) ? intval($_GET['page']) : 0;

        $sync_core_settings = $pool
            ->getClient()
            ->getSyndicationService()
            ->configurePullDashboard();

        if (!$sync_core_settings) {
            throw new \Exception('Invalid pull usage. Please refresh the page.');
        }

        /**
         * @var \Drupal\Core\Entity\EntityFieldManager $entityFieldManager
         */
        $entityFieldManager = \Drupal::service('entity_field.manager');

        foreach (Flow::getAll() as $flow) {
            foreach ($flow->getEntityTypeConfig() as $definition) {
                if (!$flow->canPullEntity($definition['entity_type_name'], $definition['bundle_name'], PullIntent::PULL_MANUALLY)) {
                    continue;
                }

                if ($entity_type_name && $definition['entity_type_name'] != $entity_type_name) {
                    continue;
                }

                if ($bundle_name && $definition['bundle_name'] != $bundle_name) {
                    continue;
                }

                if (empty($definition['import_pools'][$pool->id]) || Pool::POOL_USAGE_ALLOW != $definition['import_pools'][$pool->id]) {
                    continue;
                }

                if (isset($entity_type_ids[$pool_id][$definition['entity_type_name']][$definition['bundle_name']])) {
                    continue;
                }

                if (EntityHandlerPluginManager::isEntityTypeFieldable($definition['entity_type_name'])) {
                    /**
                     * @var \Drupal\Core\Field\FieldDefinitionInterface[] $fields
                     */
                    $fields = $entityFieldManager->getFieldDefinitions($definition['entity_type_name'], $definition['bundle_name']);

                    foreach ($fields as $key => $field) {
                        $field_config = $flow->getFieldHandlerConfig($definition['entity_type_name'], $definition['bundle_name'], $key);
                        if (empty($field_config)) {
                            continue;
                        }

                        if (empty($field_config['handler_settings']['subscribe_only_to'])) {
                            continue;
                        }

                        $allowed = [];

                        foreach ($field_config['handler_settings']['subscribe_only_to'] as $ref) {
                            $allowed[] = $ref['uuid'];
                        }

                        $sync_core_settings->ifTaggedWith(
                            $pool_id,
                            $definition['entity_type_name'],
                            $definition['bundle_name'],
                            $key,
                            $allowed
                        );
                    }
                }

                $sync_core_settings->forEntityType($pool_id, $definition['entity_type_name'], $definition['bundle_name']);

                $entity_type_ids[$pool_id][$definition['entity_type_name']][$definition['bundle_name']] = true;
            }
        }

        if (empty($entity_type_ids)) {
            $resource_response = new ResourceResponse(['message' => 'No previews available.'], self::CODE_NOT_FOUND);
            $resource_response->addCacheableDependency($cache_build);

            return $resource_response;
        }

        if (!empty($_GET['filter_title'])) {
            $sync_core_settings->searchInTitle($_GET['filter_title']);
        }
        if (!empty($_GET['filter_preview'])) {
            $sync_core_settings->searchInPreview($_GET['filter_preview']);
        }

        $sync_core_settings->publishedBetween(
            empty($_GET['filter_published_from']) ? null : (int) $_GET['filter_published_from'],
      // Include last allowed date.
      empty($_GET['filter_published_to']) ? null : (int) $_GET['filter_published_to'] + 24 * 60 * 60
        );

        try {
            $order_by_title = isset($_GET['order_by']) && 'title' == $_GET['order_by'];
            $order_ascending = isset($_GET['order_direction']) && 'asc' == $_GET['order_direction'];

            $response = $sync_core_settings
                ->runSearch($order_by_title, $order_ascending, $page);
        } catch (SyncCoreException $e) {
            $body = $e->getResponseBody();
            $code = $e->getStatusCode();
            $resource_response = new ResourceResponse($body ? $body : json_encode(['message' => $e->getMessage()]), $code ? $code : 500);
            $resource_response->addCacheableDependency($cache_build);

            return $resource_response;
        }

        foreach ($response->getItems() as $item) {
            $item->extend(
                $this->getPreviewItemData(
                    $item->getType(),
                    $item->getBundle(),
                    $item->getId()
                )
            );
        }

        $resource_response = new ResourceResponse($response->toArray());
        $resource_response->addCacheableDependency($cache_build);

        return $resource_response;
    }

    /**
     * Responds to entity POST requests.
     *
     * @throws \Exception
     *
     * @return \Symfony\Component\HttpFoundation\Response
     *                                                    -
     */
    public function post(string $pool_id, string $entity_type_name, string $bundle_name, string $shared_entity_id)
    {
        $pool = Pool::getAll()[$pool_id];

        if (!$pool) {
            return new ResourceResponse(['message' => "Unknown pool ID {$pool_id}."], self::CODE_NOT_FOUND);
        }

        $preview_item = null;

        foreach (Flow::getAll() as $flow) {
            foreach ($flow->getEntityTypeConfig() as $definition) {
                if (!$flow->canPullEntity($definition['entity_type_name'], $definition['bundle_name'], PullIntent::PULL_MANUALLY)) {
                    continue;
                }
                if ($definition['entity_type_name'] != $entity_type_name) {
                    continue;
                }
                if ($definition['bundle_name'] != $bundle_name) {
                    continue;
                }
                if (Pool::POOL_USAGE_ALLOW != $definition['import_pools'][$pool->id]) {
                    continue;
                }

                try {
                    $preview_item = $pool
                        ->getClient()
                        ->getSyndicationService()
                        ->pullSingle($flow->id, $entity_type_name, $bundle_name, $shared_entity_id)
                        ->fromPool($pool->id)
                        ->manually(true)
                        ->execute()
                        ->getPullDashboardSearchResultItem();

                    break 2;
                } catch (SyncCoreException $e) {
                    return new ResourceResponse(['message' => 'Failed to pull entity: '.$e->getMessage()], self::CODE_UNEXPECTED_ERROR);
                }
            }
        }

        if (!$preview_item) {
            return new ResourceResponse(['message' => "Missing flow for pool {$pool_id}."], self::CODE_NOT_FOUND);
        }

        $data = array_merge(
            $preview_item->toArray(),
            $this->getPreviewItemData(
                $preview_item->getType(),
                $preview_item->getBundle(),
                $preview_item->getId()
            )
        );

        // Entity was ignored by the Sync Core for some reason or the update was rejected by Drupal.
        if (empty($data['last_import'])) {
            return new ResourceResponse(['message' => 'Failed to pull entity. Pull was triggered but there\'s no new status entity.'], self::CODE_UNEXPECTED_ERROR);
        }

        return new ResourceResponse($data);
    }

    /**
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     *
     * @return array
     */
    protected function getPreviewItemData(string $entity_type_name, string $bundle_name, string $shared_entity_id)
    {
        $data = [];
        $data['entity_type_name'] = $entity_type_name;
        $data['bundle_name'] = $bundle_name;
        $data['entity_status'] = [];
        $data['last_import'] = null;
        $data['last_export'] = null;
        $data['deleted'] = false;
        $data['is_source'] = false;

        /**
         * @var \Drupal\Core\Entity\EntityInterface $entity
         */
        if (EntityHandlerPluginManager::isEntityTypeConfiguration($entity_type_name)) {
            $entity = \Drupal::entityTypeManager()
                ->getStorage($entity_type_name)
                ->load($shared_entity_id);
            $entity_uuid = null;
        } else {
            $entity = \Drupal::service('entity.repository')
                ->loadEntityByUuid($entity_type_name, $shared_entity_id);
            $entity_uuid = $shared_entity_id;
        }

        if ($entity) {
            if ($entity->hasLinkTemplate('canonical')) {
                try {
                    $url = $entity->toUrl('canonical', ['absolute' => true])
                        ->toString(true)
                        ->getGeneratedUrl();
                    $data['local_url'] = $url;
                } catch (\Exception $e) {
                }
            }

            $entity_uuid = $entity->uuid();
        }

        if (!$entity_uuid) {
            return $data;
        }

        $entity_status = EntityStatus::getInfosForEntity($entity_type_name, $entity_uuid);

        foreach ($entity_status as $info) {
            $data['entity_status'][] = [
                'flow_id' => $info->get('flow')->value,
                'pool_id' => $info->get('pool')->value,
                'last_import' => $info->getLastPull(),
                'last_export' => $info->getLastPush(),
            ];
            if (!$data['last_import'] || $data['last_import'] < $info->getLastPull()) {
                $data['last_import'] = $info->getLastPull();
            }
            if (!$data['last_export'] || $data['last_export'] < $info->getLastPush()) {
                $data['last_export'] = $info->getLastPush();
            }
            if ($info->isDeleted()) {
                $data['deleted'] = true;
            }
            if ($info->isSourceEntity()) {
                $data['is_source'] = true;
            }
        }

        // We had a bug before where the "deleted" flag wasn't set correctly locally. So we're mitigating for that here.
        if ($data['last_import'] && !$entity) {
            $data['deleted'] = true;
        }

        return $data;
    }
}
