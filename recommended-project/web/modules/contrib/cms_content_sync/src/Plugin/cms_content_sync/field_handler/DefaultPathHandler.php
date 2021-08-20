<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\field_handler;

use Drupal\cms_content_sync\Plugin\FieldHandlerBase;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Providing an implementation for the "path" field type of content entities.
 *
 * @FieldHandler(
 *   id = "cms_content_sync_default_path_handler",
 *   label = @Translation("Default Path"),
 *   weight = 90
 * )
 */
class DefaultPathHandler extends FieldHandlerBase
{
    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle, $field_name, FieldDefinitionInterface $field)
    {
        return 'path' == $field->getType();
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

        $value = $entity->get($this->fieldName)->getValue();

        // Support the pathauto module.
        $moduleHandler = \Drupal::service('module_handler');
        if ($moduleHandler->moduleExists('pathauto')) {
            $value[0]['pathauto'] = $entity->path->pathauto;
        }

        if (!empty($value)) {
            unset($value[0]['pid'], $value[0]['source']);

            $intent->setProperty($this->fieldName, $value);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function pull(PullIntent $intent)
    {
        return parent::pull($intent);
    }
}
