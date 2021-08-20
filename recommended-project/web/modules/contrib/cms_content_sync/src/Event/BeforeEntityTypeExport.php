<?php

namespace Drupal\cms_content_sync\Event;

use Symfony\Component\EventDispatcher\Event;

/**
 * An entity type is about to be exported.
 * Other modules can use this to add additional fields to the entity type
 * definition, allowing them to process additional information during push
 * and pull (by using BeforeEntityPush and BeforeEntityPull).
 * Check out the cms_content_sync_simple_sitemap submodule to see how it can
 * be used.
 */
class BeforeEntityTypeExport extends Event
{
    public const EVENT_NAME = 'cms_content_sync.entity_type.push.before';

    /**
     * Entity type.
     *
     * @var string
     */
    protected $entity_type_name;

    /**
     * Bundle.
     *
     * @var string
     */
    protected $bundle_name;

    /**
     * Entity type definition.
     *
     * @var \EdgeBox\SyncCore\Interfaces\Configuration\IDefineEntityType
     */
    protected $definition;

    /**
     * Constructs a entity export event.
     *
     * @param string                                                       $entity_type_name
     * @param string                                                       $bundle_name
     * @param \EdgeBox\SyncCore\Interfaces\Configuration\IDefineEntityType $definition
     */
    public function __construct($entity_type_name, $bundle_name, &$definition)
    {
        $this->entity_type_name = $entity_type_name;
        $this->bundle_name = $bundle_name;
        $this->definition = &$definition;
    }

    /**
     * @return string
     */
    public function getBundleName()
    {
        return $this->bundle_name;
    }

    /**
     * @return string
     */
    public function getEntityTypeName()
    {
        return $this->entity_type_name;
    }

    /**
     * @return \EdgeBox\SyncCore\Interfaces\Configuration\IDefineEntityType
     */
    public function getDefinition()
    {
        return $this->definition;
    }
}
