<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\field_handler;

use Drupal\cms_content_sync\Plugin\FieldHandlerBase;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\crop\Entity\Crop;
use Drupal\file\Entity\File;

/**
 * Providing a minimalistic implementation for any field type.
 *
 * @FieldHandler(
 *   id = "cms_content_sync_default_file_handler",
 *   label = @Translation("Default File"),
 *   weight = 90
 * )
 */
class DefaultFileHandler extends FieldHandlerBase
{
    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle, $field_name, FieldDefinitionInterface $field)
    {
        $allowed = ['image', 'file_uri', 'file', 'svg_image_field'];

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
            $file_ids = [];
            foreach ($data as $value) {
                /**
                 * @var \Drupal\file\Entity\File $file
                 */
                $file = $intent->loadEmbeddedEntity($value);
                $meta = $intent->getEmbeddedEntityData($value);
                if ($file) {
                    $meta['target_id'] = $file->id();
                    $file_ids[] = $meta;
                }
            }

            $entity->set($this->fieldName, $file_ids);
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
        $file = null;
        $invalid_subfields = ['_accessCacheability', '_attributes', '_loaded', 'top', 'target_revision_id', 'subform'];

        if ('uri' == $this->fieldDefinition->getType()) {
            $data = $entity->get($this->fieldName)->getValue();

            foreach ($data as $i => $value) {
                $files = \Drupal::entityTypeManager()
                    ->getStorage('file')
                    ->loadByProperties(['uri' => $value['value']]);
                $file = empty($files) ? null : reset($files);
                if ($file) {
                    foreach ($invalid_subfields as $invalid_subfield) {
                        unset($value[$invalid_subfield]);
                    }
                    unset($value['value']);
                    $result[] = $this->embedFile($intent, $file, $value, $entity->{$this->fieldName}[$i]);
                }
            }
        } else {
            $data = $entity->get($this->fieldName)->getValue();

            foreach ($data as $i => $value) {
                if (empty($value['target_id'])) {
                    continue;
                }

                $file = File::load($value['target_id']);
                if ($file) {
                    foreach ($invalid_subfields as $invalid_subfield) {
                        unset($value[$invalid_subfield]);
                    }
                    unset($value['target_id']);

                    $result[] = $this->embedFile($intent, $file, $value, $entity->{$this->fieldName}[$i]);
                }
            }
        }

        $intent->setProperty($this->fieldName, $result);

        return true;
    }

    /**
     * @param \Drupal\cms_content_sync\PushIntent $intent
     * @param \Drupal\file\Entity\File            $file
     * @param array                               $value
     * @param mixed                               $item
     *
     * @return array|object
     */
    protected function embedFile($intent, $file, $value, $item)
    {
        // Handle crop entities.
        $moduleHandler = \Drupal::service('module_handler');
        $crop_types = $intent->getFlow()->getEntityTypeConfig('crop', null, true);
        if ($moduleHandler->moduleExists('crop') && !empty($crop_types)) {
            $settings = $this->flow->getEntityTypeConfig('file', 'file');
            if ($settings['handler_settings']['export_crop']) {
                foreach ($crop_types as $crop_type) {
                    if (Crop::cropExists($file->getFileUri(), $crop_type['bundle_name'])) {
                        $crop = Crop::findCrop($file->getFileUri(), $crop_type['bundle_name']);
                        if ($crop) {
                            $intent->addDependency($crop);
                        }
                    }
                }
            }
        }

        return $intent->addDependency($file, $value);
    }
}
