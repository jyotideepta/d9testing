<?php

namespace Drupal\cms_content_sync\Plugin\rest\resource;

use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfo;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Renderer;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use EdgeBox\SyncCore\V2\Raw\Model\RemoteEntityListRequestMode;
use EdgeBox\SyncCore\V2\Raw\Model\RemoteEntityListResponse;
use EdgeBox\SyncCore\V2\Raw\Model\RemoteEntitySummary;
use EdgeBox\SyncCore\V2\Raw\Model\RemoteRequestQueryParamsEntityList;
use PDO;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides entity interfaces for Content Sync, allowing Sync Core v2 to
 * list entities.
 *
 * @RestResource(
 *   id = "cms_content_sync_sync_core_entity_list",
 *   label = @Translation("Content Sync: Sync Core: Entity list"),
 *   uri_paths = {
 *     "canonical" = "/rest/cms-content-sync/v2/{flow_id}"
 *   }
 * )
 */
class SyncCoreEntityListResource extends ResourceBase
{
    public const CODE_BAD_REQUEST = 400;

    protected const NONE = 'null';

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

    public function get($flow_id)
    {
        $flow = Flow::getAll()[$flow_id];
        if (empty($flow)) {
            return $this->returnError(t("The flow @flow_id doesn't exist.", ['@flow_id' => $flow_id])->render());
        }

        $query = \Drupal::request()->query->all();
        $queryObject = new RemoteRequestQueryParamsEntityList($query);

        $page = (int) $queryObject->getPage();
        if (!$page) {
            $page = 0;
        }

        $items_per_page = (int) $queryObject->getItemsPerPage();
        if (!$items_per_page) {
            $items_per_page = 0;
        }

        $mode = $queryObject->getMode();
        if (!$mode) {
            return $this->returnError(t('The mode query parameter is required.')->render());
        }

        // Need to convert miliseconds to seconds.
        $changed_after = $queryObject->getChangedAfter() ? floor((int) $queryObject->getChangedAfter() / 1000) : null;

        $entity_type = $queryObject->getNamespaceMachineName();
        $bundle = $queryObject->getMachineName();

        $skip = $page * $items_per_page;

        $database = \Drupal::database();

        /**
         * @var RemoteEntitySummary[] $items
         */
        $items = [];

        // If ALL entities are requested, we can't rely on the status entity.
        // Instead, we query for these entities by their type's table directly.
        if (RemoteEntityListRequestMode::ALL === $mode) {
            if (!$entity_type || !$bundle) {
                return $this->returnError(t("The type and bundle query parameters are required for mode 'all'.")->render());
            }

            $entity_type_storage = \Drupal::entityTypeManager()->getStorage($entity_type);
            $bundle_key = $entity_type_storage->getEntityType()->getKey('bundle');
            $id_key = $entity_type_storage->getEntityType()->getKey('id');
            $base_table = $entity_type_storage->getBaseTable();
            $data_table = $entity_type_storage->getDataTable();
            $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions($entity_type, $bundle);

            $query = $database->select($base_table, 'bt');
            $query
                ->condition('bt.'.$bundle_key, $bundle)
                ->fields('bt', [$id_key]);

            if (isset($definitions['created'])) {
                $query->join($data_table, 'dt', 'dt.'.$id_key.'= bt.'.$id_key);
                if ($changed_after) {
                    $query
                        ->condition('dt.created', $changed_after, '>');
                }
                $query
                    ->orderBy('dt.created', 'ASC');
            } else {
                $query->orderBy('bt.'.$id_key, 'ASC');
            }
            $total_number_of_items = (int) $query->countQuery()->execute()->fetchField();

            if ($total_number_of_items && $items_per_page) {
                $ids = $query
                    ->range($skip, $items_per_page)
                    ->execute()
                    ->fetchAll(PDO::FETCH_COLUMN);
                $entities = $entity_type_storage->loadMultiple($ids);
                foreach ($entities as $entity) {
                    $items[] = $this->getItem($flow, $entity, EntityStatus::getInfosForEntity($entity->getEntityTypeId(), $entity->uuid(), ['flow' => $flow_id]));
                }
            }
        } else {
            $query = $database->select('cms_content_sync_entity_status', 'cses');

            if ($entity_type && $bundle) {
                $entity_type_storage = \Drupal::entityTypeManager()->getStorage($entity_type);
                $bundle_key = $entity_type_storage->getEntityType()->getKey('bundle');
                $table = $entity_type_storage->getBaseTable();
                $query->join($table, 'bt', 'bt.uuid = cses.entity_uuid');
            }

            $query
                ->condition('cses.flow', $flow_id);

            $changed_field = RemoteEntityListRequestMode::PULLED === $mode ? 'last_import' : 'last_export';
            if ($changed_after) {
                $query->condition('cses.'.$changed_field, $changed_after, '>');
            } elseif (RemoteEntityListRequestMode::PULLED === $mode || RemoteEntityListRequestMode::PUSHED === $mode) {
                $query->condition('cses.'.$changed_field, 0, '>');
            }

            if ($entity_type) {
                $query
                    ->condition('cses.entity_type', $entity_type);
                if ($bundle) {
                    $query
                        ->condition('bt.'.$bundle_key, $bundle);
                }
            }

            if (RemoteEntityListRequestMode::PUSH_FAILED === $mode) {
                $query
                    ->where('flags&:flag=:flag', [':flag' => EntityStatus::FLAG_PUSH_FAILED]);
            }

            $query->addExpression('MIN(cses.id)', 'min_id');
            $query
                ->orderBy('min_id', 'ASC')
                ->fields('cses', ['entity_type', 'entity_uuid']);

            $query->groupBy('cses.entity_type');
            $query->groupBy('cses.entity_uuid');

            $total_number_of_items = (int) $query->countQuery()->execute()->fetchField();

            if ($total_number_of_items && $items_per_page) {
                $query->range($skip, $items_per_page);
                $ids = $query->execute()->fetchAll(PDO::FETCH_ASSOC);
                foreach ($ids as $id) {
                    $entity = \Drupal::service('entity.repository')->loadEntityByUuid(
                        $id['entity_type'],
                        $id['entity_uuid']
                    );

                    $items[] = $this->getItem($flow, $entity, EntityStatus::getInfosForEntity($id['entity_type'], $id['entity_uuid'], ['flow' => $flow_id]));
                }
            }
        }

        if (!$items_per_page) {
            $number_of_pages = $total_number_of_items;
        } else {
            $number_of_pages = ceil($total_number_of_items / $items_per_page);
        }

        $result = new RemoteEntityListResponse();
        $result->setPage($page);
        $result->setNumberOfPages($number_of_pages);
        $result->setItemsPerPage($items_per_page);
        $result->setTotalNumberOfItems($total_number_of_items);
        $result->setItems($items);

        $body = $result->jsonSerialize();

        // Turn object into array because Drupal doesn't think stdObject can be
        // serialized the same way.
        return $this->respondWith(json_decode(json_encode($body), true), 200);
    }

    protected function respondWith($body, $status)
    {
        return new ModifiedResourceResponse(
            $body,
            $status
        );
    }

    protected function returnError($message, $code = self::CODE_BAD_REQUEST)
    {
        \Drupal::logger('cms_content_sync')->notice('@not LIST: @message', [
            '@not' => 'NO',
            '@message' => $message,
        ]);

        return $this->respondWith(
            ['message' => $message],
            $code
        );
    }

    protected function getItem(Flow $flow, ?object $entity, array $statuses)
    {
        $item = new RemoteEntitySummary();

        $pools = [];
        $last_push = null;
        foreach ($statuses as $status) {
            $pools[] = $status->getPool()->id();
            if (!$last_push || $status->getLastPush() > $last_push->getLastPush()) {
                $last_push = $status;
            }
        }

        $item->setPoolMachineNames($pools);
        $item->setIsSource(empty($statuses) || (bool) $last_push);

        if ($entity) {
            $item->setEntityTypeNamespaceMachineName($entity->getEntityTypeId());
            $item->setEntityTypeMachineName($entity->bundle());
            $item->setEntityTypeVersion(Flow::getEntityTypeVersion($entity->getEntityTypeId(), $entity->bundle()));
            $item->setRemoteUuid($entity->uuid());
            if (EntityHandlerPluginManager::isEntityTypeConfiguration($entity->getEntityTypeId())) {
                $item->setRemoteUniqueId($entity->id());
            }
            $item->setLanguage($entity->language()->getId());
            $item->setName($entity->label());

            $item->setIsDeleted(false);

            $config = $flow->getEntityTypeConfig($entity->getEntityTypeId(), $entity->bundle());
            $handler = $flow->getEntityTypeHandler($config);
            $item->setViewUrl($handler->getViewUrl($entity));
        //$item->setReferenceDetails();
        } else {
            $item->setEntityTypeNamespaceMachineName($last_push->getEntityTypeName());
            $item->setEntityTypeVersion($last_push->getEntityTypeVersion());
            $item->setRemoteUuid($last_push->getUuid());

            $item->setIsDeleted($last_push->isDeleted());
        }

        return $item;
    }
}
