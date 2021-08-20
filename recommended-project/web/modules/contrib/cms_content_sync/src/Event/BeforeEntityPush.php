<?php

namespace Drupal\cms_content_sync\Event;

use Drupal\cms_content_sync\PushIntent;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * An entity is about to be pushed.
 * Other modules can use this to interact with the push, primarily to add,
 * change or remove field values.
 */
class BeforeEntityPush extends Event
{
    public const EVENT_NAME = 'cms_content_sync.entity.push.before';

    /**
     * Entity.
     *
     * @var \Drupal\Core\Entity\EntityInterface
     */
    public $entity;

    /**
     * @var intent
     */
    public $intent;

    /**
     * Constructs a extend entity push event.
     */
    public function __construct(EntityInterface $entity, PushIntent $intent)
    {
        $this->entity = $entity;
        $this->intent = $intent;
    }
}
