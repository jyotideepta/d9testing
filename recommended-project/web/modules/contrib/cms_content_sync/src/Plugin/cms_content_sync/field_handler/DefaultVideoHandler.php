<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\field_handler;

use Drupal\cms_content_sync\Plugin\FieldHandlerBase;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\file\Entity\File;

/**
 * Providing a minimalistic implementation for any field type.
 *
 * @FieldHandler(
 *   id = "cms_content_sync_default_video_handler",
 *   label = @Translation("Default Video"),
 *   weight = 90
 * )
 */
class DefaultVideoHandler extends FieldHandlerBase
{
    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle, $field_name, FieldDefinitionInterface $field)
    {
        $allowed = ['video'];

        return false !== in_array($field->getType(), $allowed);
    }

    /**
     * {@inheritdoc}
     */
    public function pull(PullIntent $intent)
    {
        $action = $intent->getAction();
        /**
         * @var \Drupal\Core\Entity\FieldableEntityInterface $entity
         */
        $entity = $intent->getEntity();

        // Deletion doesn't require any action on field basis for static data.
        if (SyncIntent::ACTION_DELETE == $action) {
            return false;
        }

        if ($intent->shouldMergeChanges()) {
            return false;
        }

        $data = $intent->getProperty($this->fieldName);

        if (empty($data)) {
            $entity->set($this->fieldName, null);
        } else {
            $result = [];
            foreach ($data as $value) {
                $meta = $intent->getEmbeddedEntityData($value);
                $file = null;

                if (empty($value['uri']) || empty($value['uuid'])) {
                    $file = $intent->loadEmbeddedEntity($value);
                } else {
                    $file = \Drupal::service('entity.repository')->loadEntityByUuid(
                        'file',
                        $value['uuid']
                    );

                    if (empty($file)) {
                        $file = File::create([
                            'uuid' => $value['uuid'],
                            'uri' => $value['uri'],
                            'filemime' => $value['mimetype'],
                            'filesize' => 1,
                        ]);
                        $file->setPermanent();
                        $file->save();
                    }
                }

                if ($file) {
                    $meta['target_id'] = $file->id();
                    $result[] = $meta;
                }
            }

            $entity->set($this->fieldName, $result);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function push(PushIntent $intent)
    {
        $action = $intent->getAction();
        /**
         * @var \Drupal\Core\Entity\FieldableEntityInterface $entity
         */
        $entity = $intent->getEntity();

        // Deletion doesn't require any action on field basis for static data.
        if (SyncIntent::ACTION_DELETE == $action) {
            return false;
        }

        $result = [];

        $data = $entity->get($this->fieldName)->getValue();

        foreach ($data as $value) {
            if (empty($value['target_id'])) {
                continue;
            }

            /**
             * @var \Drupal\file\Entity\FileInterface $file
             */
            $file = File::load($value['target_id']);
            if ($file) {
                unset($value['target_id']);
                $uri = $file->getFileUri();
                if ('public://' == substr($uri, 0, 9) || 'private://' == substr($uri, 0, 10)) {
                    $result[] = $intent->addDependency($file, $value);
                } else {
                    $value['uri'] = $uri;
                    $value['uuid'] = $file->uuid();
                    $value['mimetype'] = $file->getMimeType();
                    $result[] = $value;
                }
            }
        }

        $intent->setProperty($this->fieldName, $result);

        return true;
    }
}
