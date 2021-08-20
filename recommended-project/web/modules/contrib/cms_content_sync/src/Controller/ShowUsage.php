<?php

namespace Drupal\cms_content_sync\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Push changes controller.
 */
class ShowUsage extends ControllerBase
{
    /**
     * @param $entity_id
     * @param $entity_type
     * @param mixed $entity
     *
     * @return array the content array to theme the introduction
     */
    public function content($entity, $entity_type)
    {
        $entity = \Drupal::entityTypeManager()
            ->getStorage($entity_type)
            ->load($entity);

        return [
            '#usage' => _cms_content_sync_display_pool_usage($entity),
            '#theme' => 'cms_content_sync_show_usage',
        ];
    }
}
