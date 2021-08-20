<?php

namespace Drupal\cms_content_sync\EventSubscriber;

use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\SyncCoreFlowExport;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * A subscriber triggering a config when certain configuration changes.
 */
class ConfigSubscriber implements EventSubscriberInterface
{
    /**
     * The config Factory.
     *
     * @var \Drupal\Core\Config\ConfigFactory
     */
    protected $config_factory;

    /**
     * The Core EntityTypeManager.
     *
     * @var \Drupal\Core\Entity\EntityTypeManager
     */
    protected $entity_type_manager;

    /**
     * @param \Drupal\Core\Config\ConfigFactory $config_factory
     *   The config factory
     */
    public function __construct(ConfigFactory $config_factory, EntityTypeManagerInterface $entity_type_manager)
    {
        $this->config_factory = $config_factory;
        $this->entity_type_manager = $entity_type_manager;
    }

    /**
     * Delete unsed remote flows after config deletion.
     *
     * @param \Drupal\Core\Config\ConfigCrudEvent $event
     *   The Event to process
     */
    public function deleteUnusedFlows(ConfigCrudEvent $event)
    {
        if (str_contains($event->getConfig()->getName(), 'cms_content_sync.flow')) {
            Flow::resetFlowCache();
            SyncCoreFlowExport::deleteUnusedFlows();
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        $events[ConfigEvents::DELETE][] = ['deleteUnusedFlows'];

        return $events;
    }
}
