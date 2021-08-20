<?php

namespace Drupal\cms_content_sync\Event;

use Drupal\cms_content_sync\PullIntent;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * An entity is being pulled.
 * Modules can use this to append additional field values or process other
 * information for different use cases.
 */
class BeforeEntityPull extends Event
{
    public const EVENT_NAME = 'cms_content_sync.entity.pull.before';

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
}
