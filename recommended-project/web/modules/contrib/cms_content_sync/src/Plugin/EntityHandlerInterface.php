<?php

namespace Drupal\cms_content_sync\Plugin;

use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Specifies the publicly available methods of an entity handler plugin that can
 * be used to push and pull entities with Sync Core.
 *
 * @see \Drupal\cms_content_sync\Annotation\EntityHandler
 * @see \Drupal\cms_content_sync\Plugin\EntityHandlerBase
 * @see \Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager
 * @see \Drupal\cms_content_sync\Entity\Flow
 * @see plugin_api
 *
 * @ingroup third_party
 */
interface EntityHandlerInterface extends PluginInspectionInterface
{
    /**
     * Check if this handler supports the given entity type.
     *
     * @param string $entity_type
     * @param string $bundle
     *
     * @return bool
     */
    public static function supports($entity_type, $bundle);

    /**
     * Get the allowed push options.
     *
     * Get a list of all allowed push options for this entity.
     *
     * @see Flow::PUSH_*
     *
     * @return string[]
     */
    public function getAllowedPushOptions();

    /**
     * Get the allowed pull options.
     *
     * Get a list of all allowed pull options for this field.
     *
     * @see Flow::PULL_*
     *
     * @return string[]
     */
    public function getAllowedPullOptions();

    /**
     * @return string[]
     *                  Provide the allowed preview options used for display when manually
     *                  pulling entities
     */
    public function getAllowedPreviewOptions();

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
     * Update the entity type definition.
     *
     * Advanced entity type definition settings for the Sync Core. You
     * can usually ignore these.
     *
     * @param \EdgeBox\SyncCore\Interfaces\Configuration\IDefineEntityType $definition
     *                                                                                 The definition to be sent to Sync Core.
     *                                                                                 {@see SyncCoreExport}.
     */
    public function updateEntityTypeDefinition(&$definition);

    /**
     * Provide a list of fields that are not allowed to be pushed or pulled.
     * These fields typically contain all label fields that are pushed
     * separately anyway (we don't want to set IDs and revision IDs of entities
     * for example, but only use the UUID for references).
     *
     * @return string[]
     */
    public function getForbiddenFields();

    /**
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     *
     * @return bool
     *              Whether or not the content has been pulled. FALSE is a desired state,
     *              meaning nothing should be pulled according to config.
     */
    public function pull(PullIntent $intent);

    /**
     * @param \Drupal\cms_content_sync\PushIntent $intent
     *                                                    The request to store all relevant info at
     *
     * @throws \Drupal\cms_content_sync\Exception\SyncException
     *
     * @return bool
     *              Whether or not the content has been pushed. FALSE is a desired state,
     *              meaning nothing should be pushed according to config.
     */
    public function push(PushIntent $intent);

    /**
     * @return string
     */
    public function getViewUrl(EntityInterface $entity);
}
