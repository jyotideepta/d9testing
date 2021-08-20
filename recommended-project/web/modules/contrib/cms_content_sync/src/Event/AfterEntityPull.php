<?php

namespace Drupal\cms_content_sync\Event;

use Drupal\cms_content_sync\PullIntent;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * The entity has been pulled successfully.
 * Other modules can use this to react on successful pull events.
 */
class AfterEntityPull extends Event
{
    public const EVENT_NAME = 'cms_content_sync.entity.pull.after';

    /**
     * Entity.
     *
     * @var \Drupal\Core\Entity\EntityInterface
     */
    public $entity;

    /**
     * @var \Drupal\cms_content_sync\PullIntent
     */
    public $intent;

    /**
     * Constructs a entity pull event.
     */
    public function __construct(EntityInterface $entity, PullIntent $intent)
    {
        $this->entity = $entity;
        $this->intent = $intent;
    }

    /**
     * @return \Drupal\cms_content_sync\PullIntent
     */
    public function getIntent()
    {
        return $this->intent;
    }

    /**
     * Get the pushed entity.
     *
     * @return \Drupal\Core\Entity\EntityInterface
     */
    public function getEntity()
    {
        return $this->entity;
    }
}
