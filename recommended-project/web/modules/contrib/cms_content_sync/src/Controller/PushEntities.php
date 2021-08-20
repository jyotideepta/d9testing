<?php

namespace Drupal\cms_content_sync\Controller;

use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pull controller.
 *
 * Note that this controller is also used by the draggableviews submodule.
 */
class PushEntities extends ControllerBase
{
    /**
     * @var null|array
     */
    protected $operations = [];

    /**
     * @var \Drupal\Core\StringTranslation\TranslatableMarkup
     */
    protected $title;

    /**
     * @var array|string
     */
    protected $callback;

    /**
     * @var bool
     */
    protected $_showSkipped = false;

    /**
     * @var bool
     */
    protected $_skipUnpushed = false;

    /**
     * @var array
     */
    protected $_skippedUnpushed = [];

    /**
     * @var array
     */
    protected $_skippedNoFlow = [];

    /**
     * PushEntities constructor.
     *
     * @param null|array $existing
     */
    public function __construct($existing = null)
    {
        $this->operations = $existing;
        $this->title = t('Push content');
        $this->callback = '\Drupal\cms_content_sync\Controller\PushEntities::batchFinished';
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, $existing = null)
    {
        return new PushEntities($existing);
    }

    /**
     * @return $this
     */
    public function skipUnpushed()
    {
        $this->_skipUnpushed = true;

        return $this;
    }

    /**
     * @return $this
     */
    public function showSkipped()
    {
        if ($count = count($this->_skippedNoFlow)) {
            $list = ['#theme' => 'item_list', '#items' => $this->getSkippedNoFlow(true)];

            \Drupal::messenger()->addWarning(
                \Drupal::translation()->translate(
                    "%count items were not pushed as they're not configured to be pushed: @items",
                    [
                        '%count' => $count,
                        '@items' => \Drupal::service('renderer')->render($list),
                    ]
                )
            );
        }

        if ($count = count($this->_skippedUnpushed)) {
            $list = ['#theme' => 'item_list', '#items' => $this->getSkippedUnpushed(true)];

            \Drupal::messenger()->addStatus(
                \Drupal::translation()->translate(
                    "%count items were not pushed as they weren't pushed before: @items",
                    [
                        '%count' => $count,
                        '@items' => \Drupal::service('renderer')->render($list),
                    ]
                )
            );
        }

        return $this;
    }

    /**
     * @param bool $labelsOnly
     *
     * @return array
     */
    public function getSkippedUnpushed($labelsOnly = false)
    {
        return $labelsOnly ? $this->getLabels($this->_skippedUnpushed) : $this->_skippedUnpushed;
    }

    /**
     * @param bool $labelsOnly
     *
     * @return array
     */
    public function getSkippedNoFlow($labelsOnly = false)
    {
        return $labelsOnly ? $this->getLabels($this->_skippedNoFlow) : $this->_skippedNoFlow;
    }

    /**
     * @param \Drupal\Core\Entity\EntityInterface $entity
     *
     * @return $this
     */
    public function addEntity($entity)
    {
        $flows = PushIntent::getFlowsForEntity($entity, PushIntent::PUSH_FORCED, SyncIntent::ACTION_CREATE);

        if (!count($flows)) {
            $this->_skippedNoFlow[] = $entity;

            return $this;
        }

        $flow_id = $flows[0]->id();

        /**
         * @var \Drupal\cms_content_sync\Entity\EntityStatus[] $entity_status
         */
        $entity_status = EntityStatus::getInfosForEntity($entity->getEntityTypeId(), $entity->uuid(), ['flow' => $flow_id]);

        if ($this->_skipUnpushed) {
            if (!count($entity_status) || !$entity_status[0]->getLastPush()) {
                $this->_skippedUnpushed[] = $entity;

                return $this;
            }
        }

        $this->add($flow_id, $entity->getEntityTypeId(), $entity->id());

        return $this;
    }

    /**
     * Get the operations that were already added. You can use them when instantiating this class again later to keep the
     * existing operations and add on top of them.
     *
     * @return null|array
     */
    public function get()
    {
        return $this->operations;
    }

    /**
     * @param string $flow_id
     * @param string $entity_type_id
     * @param int    $entity_id
     *
     * @return $this
     */
    public function add($flow_id, $entity_type_id, $entity_id)
    {
        $this->operations[] = [
            '\Drupal\cms_content_sync\Controller\PushEntities::batch',
            [$flow_id, $entity_type_id, $entity_id],
        ];

        return $this;
    }

    /**
     * @param string $set
     *
     * @return $this
     */
    public function setTitle($set)
    {
        $this->title = $set;

        return $this;
    }

    /**
     * @param array|string $set
     *
     * @return $this
     */
    public function setCallback($set)
    {
        $this->callback = $set;

        return $this;
    }

    /**
     * Start the actual batch operation.
     *
     * @param null|\Drupal\Core\Url $url
     *
     * @return null|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function start($url = null)
    {
        $batch = [
            'title' => $this->title,
            'operations' => $this->operations,
            'finished' => $this->callback,
        ];

        batch_set($batch);

        if ($url) {
            return batch_process($url);
        }

        return null;
    }

    /**
     * Check if there actually are any operations to perform now.
     *
     * @return bool
     */
    public function isEmpty()
    {
        return !count($this->operations);
    }

    /**
     * Batch push finished callback.
     *
     * @param $success
     * @param $results
     * @param $operations
     */
    public static function batchFinished($success, $results, $operations)
    {
        $succeeded = count(array_filter($results));
        \Drupal::messenger()->addMessage(t('%synchronized items have been pushed to your @repository.', ['@repository' => _cms_content_sync_get_repository_name(), '%synchronized' => $succeeded]));

        $failed = count($results) - $succeeded;
        if ($failed) {
            \Drupal::messenger()->addMessage(t('%synchronized items have not been pushed to your @repository.', ['@repository' => _cms_content_sync_get_repository_name(), '%synchronized' => $failed]));
        }
    }

    /**
     * Batch push callback used by the following operations:
     * - Flow: Push All
     * - Content overview: Push changes
     * - Draggableviews: Push changes.
     *
     * @param string $flow_id
     * @param string $entity_type_id
     * @param int    $entity_id
     * @param array  $context
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public static function batch($flow_id, $entity_type_id, $entity_id, &$context)
    {
        $message = 'Pushing...';
        $results = [];
        if (isset($context['results'])) {
            $results = $context['results'];
        }

        /**
         * @var \Drupal\cms_content_sync\Entity\Flow $flow
         */
        $flow = Flow::getAll()[$flow_id];

        /**
         * @var \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
         */
        $entity_type_manager = \Drupal::service('entity_type.manager');

        $entity = $entity_type_manager
            ->getStorage($entity_type_id)
            ->load($entity_id);

        try {
            $status = PushIntent::pushEntity($entity, PushIntent::PUSH_FORCED, SyncIntent::ACTION_CREATE, $flow);
        } catch (\Exception $exception) {
            \Drupal::messenger()->addWarning(t('Item %label could not be pushed: %exception', ['%label' => $entity->label(), '%exception' => $exception->getMessage()]));
            $status = false;
        }

        $results[] = $status;

        $context['message'] = $message;
        $context['results'] = $results;
    }

    /**
     * @param $entities
     *
     * @return array
     */
    protected function getLabels($entities)
    {
        $result = [];
        foreach ($entities as $entity) {
            $result[] = $entity->label();
        }

        return $result;
    }
}
