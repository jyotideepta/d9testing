<?php

namespace Drupal\cms_content_sync\Controller;

use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\SyncCoreFlowExport;
use Drupal\cms_content_sync\SyncCorePoolExport;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Push changes controller.
 */
class FlowExport extends ControllerBase
{
    /**
     * Export flow.
     *
     * @param mixed $cms_content_sync_flow
     *
     * @throws \Exception
     */
    public function export($cms_content_sync_flow)
    {
        $flows = Flow::getAll();

        // Maybe the user just disabled the Flow but hit "Save and export" anyway.
        if (empty($flows[$cms_content_sync_flow])) {
            // So in this case we still need to make sure we update the Sync Core
            // to remove Flows that are disabled.
            SyncCoreFlowExport::deleteUnusedFlows();

            return $this->redirect('entity.cms_content_sync_flow.collection');
        }

        /**
         * @var \Drupal\cms_content_sync\Entity\Flow $flow
         */
        $flow = $flows[$cms_content_sync_flow];

        $pools = $flow->getUsedPools();
        if (empty($pools)) {
            \Drupal::messenger()->addError("This Flow doesn't use any Pools so nothing will be pushed or pulled. Please assign a Pool to this Flow first.");

            return RedirectResponse::create(
                Url::fromRoute('entity.cms_content_sync_flow.collection')->toString()
            );
        }

        foreach ($pools as $pool) {
            if (!PoolExport::validateBaseUrl($pool)) {
                return new RedirectResponse(Url::fromRoute('entity.cms_content_sync_flow.collection')->toString());
            }

            $pool_exporter = new SyncCorePoolExport($pool);
            $sites = $pool_exporter->verifySiteId();

            if ($sites && count($sites)) {
                $messenger = \Drupal::messenger();
                $messenger->addMessage($this->t('This site id is not unique, site with id %id is already registered with base url %base_url. If you changed the site URL and want to force the export, please export the pool %pool manually first.', [
                    '%id' => array_keys($sites)[0],
                    '%base_url' => array_values($sites)[0],
                    '%pool' => $pool->label(),
                ]), $messenger::TYPE_ERROR);

                return new RedirectResponse(Url::fromRoute('entity.cms_content_sync_flow.collection')->toString());
            }
        }

        $exporter = new SyncCoreFlowExport($flow);

        $batch = $exporter->prepareBatch();
        $operations = [];
        for ($i = 0; $i < $batch->count(); ++$i) {
            $operations[] = [
                [$batch->get($i), 'execute'],
                [],
            ];
        }

        $batch = [
            'title' => t('Export configuration'),
            'operations' => $operations,
            'finished' => '\Drupal\cms_content_sync\Controller\FlowExport::batchFinished',
        ];
        batch_set($batch);

        return batch_process(Url::fromRoute('entity.cms_content_sync_flow.collection'));
    }

    /**
     * Batch export finished callback.
     *
     * @param $success
     * @param $results
     * @param $operations
     */
    public static function batchFinished($success, $results, $operations)
    {
        // Disable unused Flows.
        SyncCoreFlowExport::deleteUnusedFlows();

        if ($success) {
            $message = t('Flow has been exported.');
        } else {
            $message = t('Flow export failed.');
        }

        \Drupal::messenger()->addMessage($message);
    }
}
