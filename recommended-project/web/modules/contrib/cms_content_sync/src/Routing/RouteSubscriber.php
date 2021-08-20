<?php

namespace Drupal\cms_content_sync\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class RouteSubscriber extends RouteSubscriberBase
{
    /**
     * {@inheritdoc}
     */
    protected function alterRoutes(RouteCollection $collection)
    {
        // Change page title based on whether the subscriber is using the cloud or self-hosted version.
        if ($route = $collection->get('entity.cms_content_sync.content')) {
            $route->setDefault('_title', _cms_content_sync_get_repository_name()->getUntranslatedString());
        }
    }
}
