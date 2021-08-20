<?php

namespace Drupal\cms_content_sync\Controller;

use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\SyncCoreFlowExport;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Pull controller.
 */
class SetFlowStatus extends ControllerBase
{
    /**
     * Set flow status.
     *
     * @param mixed $cms_content_sync_flow
     */
    public function setStatus($cms_content_sync_flow)
    {
        /**
         * @var \Drupal\cms_content_sync\Entity\Flow $flow
         */
        $flow = \Drupal::entityTypeManager()
            ->getStorage('cms_content_sync_flow')
            ->load($cms_content_sync_flow);

        if ($flow->status()) {
            $flow->set('status', false);
            \Drupal::messenger()->addMessage($this->t('The flow @flow_name has been disabled.', ['@flow_name' => $flow->label()]));
        } else {
            $flow->set('status', true);
            \Drupal::messenger()->addMessage($this->t('The flow @flow_name has been enabled.', ['@flow_name' => $flow->label()]));
        }
        $flow->save();

        Flow::resetFlowCache();
        SyncCoreFlowExport::deleteUnusedFlows();

        return new RedirectResponse(Url::fromRoute('entity.cms_content_sync_flow.collection')->toString());
    }
}
