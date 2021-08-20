<?php

namespace Drupal\cms_content_sync\Plugin;

use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use EdgeBox\SyncCore\Interfaces\Configuration\IDefineEntityType;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Common base class for field handler plugins.
 *
 * @see \Drupal\cms_content_sync\Annotation\EntityHandler
 * @see \Drupal\cms_content_sync\Plugin\FieldHandlerInterface
 * @see plugin_api
 *
 * @ingroup third_party
 */
abstract class FieldHandlerBase extends PluginBase implements ContainerFactoryPluginInterface, FieldHandlerInterface
{
    /**
     * A logger instance.
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var string
     */
    protected $entityTypeName;

    /**
     * @var string
     */
    protected $bundleName;

    /**
     * @var string
     */
    protected $fieldName;

    /**
     * @var \Drupal\Core\Field\FieldDefinitionInterface
     */
    protected $fieldDefinition;

    /**
     * @var array
     *            Additional settings as provided by
     *            {@see FieldHandlerInterface::getHandlerSettings}
     */
    protected $settings;

    /**
     * @var \Drupal\cms_content_sync\Entity\Flow
     */
    protected $flow;

    /**
     * Constructs a Drupal\rest\Plugin\ResourceBase object.
     *
     * @param array                    $configuration
     *                                                    A configuration array containing information about the plugin instance.
     *                                                    Must contain entity_type_name, bundle_name, field_name, field_definition,
     *                                                    settings and sync (see above).
     * @param string                   $plugin_id
     *                                                    The plugin_id for the plugin instance
     * @param mixed                    $plugin_definition
     *                                                    The plugin implementation definition
     * @param \Psr\Log\LoggerInterface $logger
     *                                                    A logger instance
     */
    public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger)
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->logger = $logger;
        $this->entityTypeName = $configuration['entity_type_name'];
        $this->bundleName = $configuration['bundle_name'];
        $this->fieldName = $configuration['field_name'];
        $this->fieldDefinition = $configuration['field_definition'];
        $this->settings = $configuration['settings'];
        $this->flow = $configuration['sync'];
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('cms_content_sync')
    );
    }

    /**
     * @return string
     */
    public function getFieldName()
    {
        return $this->fieldName;
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedPushOptions()
    {
        return [
            PushIntent::PUSH_DISABLED,
            PushIntent::PUSH_AUTOMATICALLY,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedPullOptions()
    {
        return [
            PullIntent::PULL_DISABLED,
            PullIntent::PULL_AUTOMATICALLY,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getHandlerSettings($current_values, $type = 'both')
    {
        // Nothing special here.
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function validateHandlerSettings(array &$form, FormStateInterface $form_state, $settings_key, $current_values)
    {
        // No settings means no validation.
    }

    /**
     * {@inheritdoc}
     */
    public function pull(PullIntent $intent)
    {
        $action = $intent->getAction();
        $entity = $intent->getEntity();

        // Deletion doesn't require any action on field basis for static data.
        if (SyncIntent::ACTION_DELETE == $action) {
            return false;
        }

        if ($intent->shouldMergeChanges() && !$this->forceMergeOverwrite()) {
            return false;
        }

        if (PullIntent::PULL_AUTOMATICALLY != $this->settings['import']) {
            return false;
        }

        // These fields can't be changed.
        if (!$entity->isNew()) {
            if ('default_langcode' === $this->fieldName) {
                return true;
            }
        }

        $data = $intent->getProperty($this->fieldName);

        if (empty($data)) {
            $entity->set($this->fieldName, null);
        } else {
            $entity->set($this->fieldName, $data);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function push(PushIntent $intent)
    {
        $action = $intent->getAction();
        $entity = $intent->getEntity();

        if (PushIntent::PUSH_AUTOMATICALLY != $this->settings['export']) {
            return false;
        }

        // Deletion doesn't require any action on field basis for static data.
        if (SyncIntent::ACTION_DELETE == $action) {
            return false;
        }

        $intent->setProperty($this->fieldName, $entity->get($this->fieldName)->getValue());

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function definePropertyAtType(IDefineEntityType $type_definition)
    {
        $type_definition->addObjectProperty($this->fieldName, $this->fieldDefinition->getLabel(), true, $this->fieldDefinition->isRequired());
    }

    /**
     * @return false
     */
    protected function forceMergeOverwrite()
    {
        return 'changed' == $this->fieldName;
    }
}
