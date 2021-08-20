<?php

namespace Drupal\cms_content_sync\Event;

use Drupal\cms_content_sync\Entity\FlowInterface;
use Drupal\cms_content_sync\Entity\PoolInterface;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * The entity has been pushed successfully.
 * Other modules can use this to react on successful push events.
 */
class AfterEntityPush extends Event
{
    public const EVENT_NAME = 'cms_content_sync.entity.push.after';

    /**
     * Entity.
     *
     * @var \Drupal\Core\Entity\EntityInterface
     */
    protected $entity;

    /**
     * The pool the entity got pushed to.
     *
     * @var \Drupal\cms_content_sync\Entity\PoolInterface
     */
    protected $pool;

    /**
     * The flow that was used to push the entity.
     *
     * @var \Drupal\cms_content_sync\Entity\FlowInterface
     */
    protected $flow;

    /**
     * The reason the entity got pushed.
     */
    protected $reason;

    /**
     * Action.
     */
    protected $action;

    /**
     * Constructs a entity push event.
     *
     * @param $reason
     * @param $action
     */
    public function __construct(EntityInterface $entity, PoolInterface $pool, FlowInterface $flow, $reason, $action)
    {
        $this->entity = $entity;
        $this->pool = $pool;
        $this->flow = $flow;
        $this->reason = $reason;
        $this->action = $action;
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
