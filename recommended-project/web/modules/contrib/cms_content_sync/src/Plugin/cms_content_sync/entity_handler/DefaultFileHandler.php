<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\entity_handler;

use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Exception\SyncException;
use Drupal\cms_content_sync\Plugin\EntityHandlerBase;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\crop\Entity\Crop;

/**
 * Class DefaultFileHandler, providing proper file handling capabilities.
 *
 * @EntityHandler(
 *   id = "cms_content_sync_default_file_handler",
 *   label = @Translation("Default File"),
 *   weight = 90
 * )
 */
class DefaultFileHandler extends EntityHandlerBase
{
    public const USER_PROPERTY = 'uid';

    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle)
    {
        return 'file' == $entity_type;
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
        $moduleHandler = \Drupal::service('module_handler');
        $crop_types = \Drupal::entityTypeManager()->getStorage('crop_type')->loadMultiple();
        if ($moduleHandler->moduleExists('crop') && !empty($crop_types) && 'pull' !== $type) {
            return [
                'export_crop' => [
                    '#type' => 'checkbox',
                    '#title' => 'Push cropping',
                    '#default_value' => isset($current_values['export_crop']) && 0 === $current_values['export_crop'] ? 0 : 1,
                ],
            ];
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function validateHandlerSettings(array &$form, FormStateInterface $form_state, $settings_key, $current_values)
    {
        // Ensure that at least one crop bundle is enabled if export_crop is set.
        if (isset($this->settings['handler_settings']['export_crop']) && $this->settings['handler_settings']['export_crop']) {
            $crop_types = \Drupal::entityTypeManager()->getStorage('crop_type')->loadMultiple();

            foreach ($crop_types as $crop_type_id => $crop_type) {
                if (isset($current_values['sync_entities']['crop-'.$crop_type_id])) {
                    if (Flow::HANDLER_IGNORE == $current_values['sync_entities']['crop-'.$crop_type_id]['handler']) {
                        continue;
                    }

                    return;
                }
            }

            $form_state->setError(
                $form[$this->entityTypeName][$this->bundleName],
                t(
                    'You have configured file entities to push crop entities but did not configure any crop entity type bundle to be exported.',
                )
            );
        }
    }

    /**
     * @param \EdgeBox\SyncCore\Interfaces\Configuration\IDefineEntityType $definition
     */
    public function updateEntityTypeDefinition(&$definition)
    {
        parent::updateEntityTypeDefinition($definition);

        $definition->isFile(true);

        $definition->addObjectProperty('uri', 'URI', true, true);
    }

    /**
     * {@inheritdoc}
     */
    public function getForbiddenFields()
    {
        return array_merge(
            parent::getForbiddenFields(),
            [
                'uri',
                'filemime',
                'filesize',
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function pull(PullIntent $intent)
    {
        /**
         * @var \Drupal\file\FileInterface $entity
         */
        $entity = $intent->getEntity();
        $action = $intent->getAction();

        if (SyncIntent::ACTION_DELETE == $action) {
            if ($entity) {
                return $this->deleteEntity($entity);
            }

            return false;
        }

        $uri = $intent->getProperty('uri');
        if (empty($uri)) {
            throw new SyncException(SyncException::CODE_INVALID_PULL_REQUEST);
        }
        if (!empty($uri[0]['value'])) {
            $uri = $uri[0]['value'];
        }

        if ('http://' == substr($uri, 0, 7) || 'https://' == substr($uri, 0, 8)) {
            if (!$entity) {
                $entity_type = \Drupal::entityTypeManager()->getDefinition($intent->getEntityType());

                $base_data = [];

                if ($this->hasLabelProperty()) {
                    $base_data[$entity_type->getKey('label')] = $intent->getOperation()->getName();
                }

                $base_data[$entity_type->getKey('uuid')] = $intent->getUuid();
                if ($entity_type->getKey('langcode')) {
                    $base_data[$entity_type->getKey('langcode')] = $intent->getProperty($entity_type->getKey('langcode'));
                }

                $base_data['uri'] = $uri;

                $storage = \Drupal::entityTypeManager()->getStorage($intent->getEntityType());
                $entity = $storage->create($base_data);
            }

            $entity->set('filename', $intent->getOperation()->getName());
            $entity->set('uri', $uri);
            $entity->save();

            return true;
        }

        $content = $intent
            ->getOperation()
            ->downloadFile();
        if (!$content) {
            throw new SyncException(SyncException::CODE_INVALID_PULL_REQUEST);
        }

        if ($entity) {
            // Drupal will re-use the existing file entity and keep it's ID, but
            // *change the UUID* of the file entity to a new random value
            // So we have to tell Drupal we actually want to keep it so references
            // to it keep working for us. That's why we can't use file_save_data- it doesn't do what it promises (keeping the
            // file entity and just replacing the file content).
            if ($uri = \Drupal::service('file_system')->saveData($content, $entity->getFileUri(), FileSystemInterface::EXISTS_REPLACE)) {
                $entity->set('filename', $intent->getOperation()->getName());
                $entity->save();

                return true;
            }

            throw new SyncException(SyncException::CODE_ENTITY_API_FAILURE);
        } else {
            $directory = \Drupal::service('file_system')->dirname($uri);
            $was_prepared = \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

            if ($was_prepared) {
                /** @var FileInterface[] $existing_files */
                $existing_files = \Drupal::entityTypeManager()
                    ->getStorage('file')
                    ->loadByProperties(['uri' => $uri]);

                $entity = file_save_data($content, $uri, FileSystemInterface::EXISTS_REPLACE);

                // Drupal has a pending issue: https://www.drupal.org/node/2241865
                // so it creates a new file entity even when overwriting an existing entity. This will throw an exception if we
                // now try to save the new file with the same UUID as the old file.
                // So if we're updating an existing file, we don't create a new file entity so just skipping the file safe.
                if (count($existing_files)) {
                    $existing = reset($existing_files);
                    // Yes, file exists and UUID matches. So no need to create a new file entity.
                    if ($existing->uuid() === $intent->getUuid()) {
                        // Delete duplicated file until Drupal resolves the issue above.
                        if ($entity->uuid() !== $intent->getUuid()) {
                            $entity->delete();
                        }

                        return true;
                    }
                }

                $entity->setPermanent();
                $entity->set('uuid', $intent->getUuid());
                $entity->set('filename', $intent->getOperation()->getName());
                $entity->save();

                return true;
            }

            throw new SyncException(SyncException::CODE_ENTITY_API_FAILURE);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function push(PushIntent $intent, EntityInterface $entity = null)
    {
        /**
         * @var \Drupal\file\FileInterface $entity
         */
        if (!$entity) {
            $entity = $intent->getEntity();
        }

        if (!parent::push($intent)) {
            return false;
        }

        // Base Info.
        $uri = $entity->getFileUri();
        $content = file_get_contents($uri);
        // File was removed from the file system. Trying to import it at another site will throw an error there and as the
        // source of the error is here, we throw an Error here.
        if (false === $content) {
            \Drupal::logger('cms_content_sync')->error(
                'Can\'t push file: File @uri doesn\'t exist in the file system or the file permissions forbid access.<br>Flow: @flow_id | Pool: @pool_id',
                [
                    '@uri' => $uri,
                    '@flow_id' => $intent->getFlow()->id(),
                    '@pool_id' => $intent->getPool()->id(),
                ]
            );

            throw new \Exception("Can't push file: File ".$uri." doesn't exist in the file system or the file permissions forbid access.");
        }

        $intent->getOperation()->uploadFile($content, $entity->getFilename());
        $intent->setProperty('uri', [['value' => $uri]]);
        $intent->getOperation()->setName($entity->getFilename(), $intent->getActiveLanguage());

        // Preview.
        $view_mode = $this->flow->getPreviewType($entity->getEntityTypeId(), $entity->bundle());
        if (Flow::PREVIEW_DISABLED != $view_mode) {
            $this->setPreviewHtml('<img style="max-height: 200px" src="'.file_create_url($uri).'"/>', $intent);
        }

        $intent->getOperation()->setSourceDeepLink($this->getViewUrl($entity), $intent->getActiveLanguage());

        // Handle focal point crop entities.
        $moduleHandler = \Drupal::service('module_handler');
        $crop_types = $intent->getFlow()->getEntityTypeConfig('crop', null, true);
        if ($moduleHandler->moduleExists('crop') && !empty($crop_types)) {
            if ($this->settings['handler_settings']['export_crop']) {
                foreach ($crop_types as $crop_type) {
                    if (Crop::cropExists($uri, $crop_type['bundle_name'])) {
                        $crop = Crop::findCrop($uri, $crop_type['bundle_name']);
                        if ($crop) {
                            $intent->addDependency($crop);

                            $intent->setStatusData('crop', $crop->position());
                        }
                    }
                }
            }
        }

        return true;
    }

    public function getViewUrl(EntityInterface $entity)
    {
        $uri = $entity->getFileUri();

        return \Drupal\Core\Url::fromUri(file_create_url($uri))->toString();
    }

    protected function getEntityName(EntityInterface $file, PushIntent $intent)
    {
        /**
         * @var File $file
         */
        return $file->getFilename();
    }
}
