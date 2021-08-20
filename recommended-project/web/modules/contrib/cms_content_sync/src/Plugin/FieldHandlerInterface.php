<?php

namespace Drupal\cms_content_sync\Plugin;

use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use EdgeBox\SyncCore\Interfaces\Configuration\IDefineEntityType;

/**
 * Specifies the publicly available methods of a field handler plugin that can
 * be used to push and pull fields with Sync Core.
 *
 * @see \Drupal\cms_content_sync\Annotation\FieldHandler
 * @see \Drupal\cms_content_sync\Plugin\FieldHandlerBase
 * @see \Drupal\cms_content_sync\Plugin\Type\FieldHandlerPluginManager
 * @see \Drupal\cms_content_sync\Entity\Flow
 * @see plugin_api
 *
 * @ingroup third_party
 */
interface FieldHandlerInterface extends PluginInspectionInterface
{
    /**
     * Check if this handler supports the given field instance.
     *
     * @param string $entity_type
     * @param string $bundle
     * @param string $field_name
     *
     * @return bool
     */
    public static function supports($entity_type, $bundle, $field_name, FieldDefinitionInterface $field);

    /**
     * Get the allowed push options.
     *
     * Get a list of all allowed push options for this field. You can
     * either allow {@see PushIntent::PUSH_DISABLED} or
     * {@see PushIntent::PUSH_DISABLED} and
     * {@see PushIntent::PUSH_AUTOMATICALLY}.
     *
     * @return string[]
     */
    public function getAllowedPushOptions();

    /**
     * Get the allowed pull options.
     *
     * Get a list of all allowed pull options for this field. You can
     * either allow {@see PullIntent::PULL_DISABLED} or
     * {@see PullIntent::PULL_DISABLED} and
     * {@see PullIntent::PULL_AUTOMATICALLY}.
     *
     * @return string[]
     */
    public function getAllowedPullOptions();

    /**
     * Get the handler settings.
     *
     * Return the actual form elements for any additional settings for this
     * handler.
     *
     * @param array  $current_values
     *                               The current values that the user set, if any
     * @param string $type:
     *                               One of 'pull', 'push', 'both'
     *
     * @return array
     */
    public function getHandlerSettings($current_values, $type = 'both');

    /**
     * Validate the settings defined above. $form and $form_state are the same as
     * in the Form API. $settings_key is the index at $form['sync_entities'] for
     * this handler instance.
     *
     * @param $settings_key
     * @param $current_values
     *
     * @return mixed
     */
    public function validateHandlerSettings(array &$form, FormStateInterface $form_state, $settings_key, $current_values);

    /**
     * @param \Drupal\cms_content_sync\SyncIntent $intent
     *                                                    The request containing all pushed data
     *
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     *
     * @return bool
     *              Whether or not the content has been pulled. FALSE is a desired state,
     *              meaning the entity should not be pulled according to config.
     */
    public function pull(PullIntent $intent);

    /**
     * @param \Drupal\cms_content_sync\SyncIntent $intent
     *
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     *
     * @return bool
     *              Whether or not the content has been pushed. FALSE is a desired state,
     *              meaning the entity should not be pushed according to config.
     */
    public function push(PushIntent $intent);

    /**
     * @return string the field name this handler belongs to
     */
    public function getFieldName();

    /**
     * Provide the Sync Core with the right property definition so this field can be stored
     * and synchronized.
     */
    public function definePropertyAtType(IDefineEntityType $type_definition);
}
