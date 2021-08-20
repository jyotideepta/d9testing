<?php

namespace Drupal\cms_content_sync\Controller;

use Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager;
use Drupal\Core\Controller\ControllerBase;

/**
 * Class Compatibility provides details about entity types and field types used on this site
 * and whether or not we support them.
 */
class Compatibility extends ControllerBase
{
    /**
     * @return array the content array to theme the compatibility tables
     */
    public function content()
    {
        return [
            '#supported_entity_types' => EntityHandlerPluginManager::getEntityTypes(),
            '#theme' => 'cms_content_sync_compatibility',
        ];
    }
}
