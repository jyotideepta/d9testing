<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\entity_handler;

use Drupal\cms_content_sync\Plugin\EntityHandlerBase;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Class DefaultNodeHandler, providing proper handling for published/unpublished
 * content.
 *
 * @EntityHandler(
 *   id = "cms_content_sync_default_node_handler",
 *   label = @Translation("Default Node"),
 *   weight = 90
 * )
 */
class DefaultNodeHandler extends EntityHandlerBase
{
    public const USER_PROPERTY = 'uid';
    public const USER_REVISION_PROPERTY = 'revision_uid';
    public const REVISION_TRANSLATION_AFFECTED_PROPERTY = 'revision_translation_affected';

    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle)
    {
        return 'node' == $entity_type;
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedPushOptions()
    {
        return [
            PushIntent::PUSH_DISABLED,
            PushIntent::PUSH_AUTOMATICALLY,
            PushIntent::PUSH_AS_DEPENDENCY,
            PushIntent::PUSH_MANUALLY,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function push(PushIntent $intent, EntityInterface $entity = null)
    {
        if (!parent::push($intent, $entity)) {
            return false;
        }

        if (!$entity) {
            $entity = $intent->getEntity();
        }

        /**
         * @var \Drupal\node\NodeInterface $entity
         */
        $this->setDateProperty($intent, 'created', intval($entity->getCreatedTime()));

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function setEntityValues(PullIntent $intent, FieldableEntityInterface $entity = null)
    {
        if (!$entity) {
            $entity = $intent->getEntity();
        }
        $entity->setRevisionCreationTime(time());
        if ($intent->getProperty('revision_log')) {
            $entity->setRevisionLogMessage(reset($intent->getProperty('revision_log')[0]));
        }

        return parent::setEntityValues($intent, $entity);
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedPreviewOptions()
    {
        return [
            'table' => 'Table',
            'preview_mode' => 'Preview mode',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getHandlerSettings($current_values, $type = 'both')
    {
        $options = parent::getHandlerSettings($current_values, $type);

        // @todo Move to default handler for all entities that can be published.
        $options['ignore_unpublished'] = [
            '#type' => 'checkbox',
            '#title' => 'Ignore unpublished content',
            '#default_value' => isset($current_values['ignore_unpublished']) && 0 === $current_values['ignore_unpublished'] ? 0 : 1,
        ];

        $options['allow_explicit_unpublishing'] = [
            '#type' => 'checkbox',
            '#title' => 'Allow explicit unpublishing',
            '#default_value' => isset($current_values['allow_explicit_unpublishing']) && 0 === $current_values['allow_explicit_unpublishing'] ? 0 : 1,
        ];

        return $options;
    }

    /**
     * {@inheritdoc}
     */
    public function ignorePull(PullIntent $intent)
    {
        // Not published? Ignore this revision then.
        if (empty($intent->getProperty('status')[0]['value']) && $this->settings['handler_settings']['ignore_unpublished']) {
            if (!$this->settings['handler_settings']['allow_explicit_unpublishing'] || SyncIntent::ACTION_CREATE === $intent->getAction()) {
                // Unless it's a delete, then it won't have a status and is independent
                // of published state, so we don't ignore the pull.
                if (SyncIntent::ACTION_DELETE != $intent->getAction()) {
                    return true;
                }
            }
        }

        return parent::ignorePull($intent);
    }

    /**
     * {@inheritdoc}
     */
    public function ignorePush(PushIntent $intent)
    {
        /**
         * @var \Drupal\node\NodeInterface $entity
         */
        $entity = $intent->getEntity();
        $node_storage = \Drupal::entityTypeManager()->getStorage('node');
        $node = $node_storage->load($entity->id());

        if (!$entity->isPublished() && $this->settings['handler_settings']['ignore_unpublished']) {
            if (!$this->settings['handler_settings']['allow_explicit_unpublishing'] || $node->isPublished() || ($entity->getRevisionId() == $node->getRevisionId() && !$intent->getEntityStatus()->getLastPush())) {
                return true;
            }
        }

        return parent::ignorePush($intent);
    }
}
