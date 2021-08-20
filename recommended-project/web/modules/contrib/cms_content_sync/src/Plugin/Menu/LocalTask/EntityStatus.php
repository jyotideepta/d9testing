<?php

namespace Drupal\cms_content_sync\Plugin\Menu\LocalTask;

use Drupal\Core\Menu\LocalTaskDefault;
use Symfony\Component\HttpFoundation\Request;

/**
 * Local task plugin to render dynamic tab title dynamically.
 */
class EntityStatus extends LocalTaskDefault
{
    /**
     * {@inheritdoc}
     */
    public function getTitle(Request $request = null)
    {
        return _cms_content_sync_get_repository_name();
    }
}
