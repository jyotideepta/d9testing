<?php

namespace Drupal\cms_content_sync\Controller;

use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\PushIntent;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Pull controller.
 *
 * Note that this controller is also used by the draggableviews submodule.
 */
class FlowPush extends ControllerBase
{
    /**
     * @var int
     */
    public const PREPARATION_BATCH_SIZE = 100;

    /**
     * Push all entities of the flow.
     *
     * @param string $cms_content_sync_flow
     * @param string $push_mode
     */
    public function push($cms_content_sync_flow, $push_mode)
    {
        /**
         * @var \Drupal\cms_content_sync\Entity\Flow $flow
         */
        $flow = Flow::getAll()[$cms_content_sync_flow];

        /**
         * @var \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
         */
        $entity_type_manager = \Drupal::service('entity_type.manager');

        $operations = [];
        foreach ($flow->getEntityTypeConfig(null, null, true) as $config) {
            if ('automatic_manual' == $push_mode || 'automatic_manual_force' == $push_mode) {
                if (PushIntent::PUSH_AUTOMATICALLY != $config['export'] && PushIntent::PUSH_MANUALLY != $config['export']) {
                    continue;
                }
            } else {
                if (PushIntent::PUSH_AUTOMATICALLY != $config['export']) {
                    continue;
                }
            }

            $storage = $entity_type_manager->getStorage($config['entity_type_name']);

            $query = $storage->getQuery();

            // Files don't have bundles, so this would lead to a fatal error then.
            if ($storage->getEntityType()->getKey('bundle')) {
                $query = $query->condition($storage->getEntityType()->getKey('bundle'), $config['bundle_name']);
            }

            $query = $query->count();

            $count = $query->execute();

            if (!$count) {
                continue;
            }

            // @todo A better way would be to have one batch operation that does all
            //   of that and then just dynamically updates the progress.
            //   {@see FlowPull} for an example.
            $pages = ceil($count / self::PREPARATION_BATCH_SIZE);
            for ($i = 0; $i < $pages; ++$i) {
                $operations[] = [
                    '\Drupal\cms_content_sync\Controller\FlowPush::batch',
                    [$cms_content_sync_flow, $config['entity_type_name'], $config['bundle_name'], $push_mode, $i, $count, false],
                ];
            }
        }

        $operations[count($operations) - 1][1][6] = true;

        $batch = [
            'title' => t('Push items...'),
            'operations' => $operations,
            'finished' => '\Drupal\cms_content_sync\Controller\FlowPush::batchFinished',
        ];

        batch_set($batch);

        return batch_process();
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
        return RedirectResponse::create(Url::fromRoute('entity.cms_content_sync_flow.collection')->toString());
    }

    /**
     * Batch push callback for the push-all operation.
     *
     * @param string $flow_id
     * @param string $entity_type_id
     * @param string $bundle_name
     * @param string $push_mode
     * @param int    $page
     * @param int    $count
     * @param $last
     * @param $context
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    public static function batch($flow_id, $entity_type_id, $bundle_name, $push_mode, $page, $count, $last, &$context)
    {
        $message = 'Checking '.$entity_type_id.'.'.$bundle_name.' page '.($page + 1).'/'.ceil($count / self::PREPARATION_BATCH_SIZE).'...';
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

        $operations = new PushEntities();

        $storage = $entity_type_manager->getStorage($entity_type_id);

        $query = $storage->getQuery();

        // Files don't have bundles, so this would lead to a fatal error then.
        if ($storage->getEntityType()->getKey('bundle')) {
            $query = $query->condition($storage->getEntityType()->getKey('bundle'), $bundle_name);
        }

        $query = $query->range($page * self::PREPARATION_BATCH_SIZE, self::PREPARATION_BATCH_SIZE);

        $ids = $query->execute();

        foreach ($ids as $id) {
            $entity = $storage->load($id);

            /**
             * @var \Drupal\cms_content_sync\Entity\EntityStatus[] $entity_status
             */
            $entity_status = EntityStatus::getInfosForEntity($entity->getEntityTypeId(), $entity->uuid(), ['flow' => $flow->id()]);

            $is_manual = PushIntent::PUSH_MANUALLY == $flow->getEntityTypeConfig($entity->getEntityTypeId(), $entity->bundle())['export'];

            // If this is manual AND the export doesn't say FORCE, we skip this entity if it wasn't exported before.
            if ('automatic_manual' == $push_mode && $is_manual && (empty($entity_status) || is_null($entity_status[0]->getLastPush()))) {
                continue;
            }

            $operations->add($flow_id, $entity_type_id, $id);
        }

        $context['message'] = $message;
        $context['results'] = array_merge($results, $operations->get());

        // can't do this in the finished callback unfortunately as Drupal errs out with "No active batch" then or goes into
        // an infinite batch loop.
        if ($last) {
            $pusher = new PushEntities($context['results']);
            $pusher->start();
        }
    }
}
