<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\entity_handler;

use Drupal\cms_content_sync\MissingDependencyManager;
use Drupal\cms_content_sync\Plugin\EntityHandlerBase;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\Core\Entity\EntityInterface;
use Drupal\crop\Entity\Crop;

/**
 * Class DefaultCropHandler, providing a minimalistic implementation for the
 * crop entity type.
 *
 * @EntityHandler(
 *   id = "cms_content_sync_crop_handler",
 *   label = @Translation("Default Crop"),
 *   weight = 90
 * )
 */
class DefaultCropHandler extends EntityHandlerBase
{
    public const USER_REVISION_PROPERTY = 'revision_uid';

    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle)
    {
        return 'crop' == $entity_type;
    }

    /**
     * {@inheritdoc}
     */
    public function getHandlerSettings($current_values, $type = 'both')
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedPushOptions()
    {
        return [
            PushIntent::PUSH_DISABLED,
            PushIntent::PUSH_AS_DEPENDENCY,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedPullOptions()
    {
        return [
            PullIntent::PULL_DISABLED,
            PullIntent::PULL_AS_DEPENDENCY,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function pull(PullIntent $intent)
    {
        /** @var FileInterface[] $files */
        $files = \Drupal::entityTypeManager()
            ->getStorage('file')
            ->loadByProperties(['uri' => $intent->getProperty('uri')[0]['value']]);

        // Unset the entity_id field so that it gets automatically set during
        // the entity creation.
        if (!count($files)) {
            $intent->overwriteProperty('entity_id', null);
        } else {
            /**
             * @var \Drupal\file\FileInterface $file
             */
            $file = reset($files);
            $this->deleteExistingCrop($intent, $file);
            $intent->overwriteProperty('entity_id', $file->id());
        }

        if (!parent::pull($intent)) {
            return false;
        }

        if (!count($files)) {
            MissingDependencyManager::saveUnresolvedDependency(
                'file',
                $intent->getProperty('uri')[0]['value'],
                $intent->getEntity(),
                $intent->getReason(),
                'entity_id'
            );
        }

        return true;
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

    public function getViewUrl(EntityInterface $entity)
    {
        $uri = $entity->get('uri')->getValue()[0]['value'];

        /** @var FileInterface[] $files */
        $files = \Drupal::entityTypeManager()
            ->getStorage('file')
            ->loadByProperties(['uri' => $uri]);

        if (empty($files)) {
            return \Drupal::urlGenerator()->generateFromRoute('<front>', [], ['absolute' => true]);
        }

        $file = reset($files);

        $uri = $file->getFileUri();

        return \Drupal\Core\Url::fromUri(file_create_url($uri))->toString();
    }

    /**
     * Delete already existing Crop entity.
     *
     * @param $file
     */
    private function deleteExistingCrop(PullIntent $intent, $file)
    {
        $moduleHandler = \Drupal::service('module_handler');
        if ($moduleHandler->moduleExists('crop')) {
            $crop = Crop::findCrop($file->getFileUri(), $intent->getBundle());
            if ($crop && $crop->uuid() != $intent->getUuid()) {
                $crop->delete();
            }
        }
    }
}
