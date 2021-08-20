<?php

namespace Drupal\cms_content_sync\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Derivative class that provides the menu links for the Products.
 */
class ContentDashboard extends DeriverBase implements ContainerDeriverInterface
{
    /**
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    /**
     * Creates a ProductMenuLink instance.
     *
     * @param $base_plugin_id
     */
    public function __construct($base_plugin_id, EntityTypeManagerInterface $entity_type_manager)
    {
        $this->entityTypeManager = $entity_type_manager;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, $base_plugin_id)
    {
        return new static(
      $base_plugin_id,
      $container->get('entity_type.manager')
    );
    }

    /**
     * {@inheritdoc}
     */
    public function getDerivativeDefinitions($base_plugin_definition)
    {
        $links = [];

        $links['content_dashboard'] = [
            'title' => _cms_content_sync_get_repository_name(),
            'menu_name' => 'admin',
            'parent' => 'system.admin_content',
            'route_name' => 'entity.cms_content_sync.content',
        ] + $base_plugin_definition;

        return $links;
    }
}
