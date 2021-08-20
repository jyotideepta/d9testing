<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\field_handler;

use Drupal\cms_content_sync\Plugin\FieldHandlerBase;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StreamWrapper\PublicStream;

/**
 * Providing a minimalistic implementation for any field type.
 *
 * @FieldHandler(
 *   id = "cms_content_sync_default_formatted_text_handler",
 *   label = @Translation("Default Formatted Text"),
 *   weight = 90
 * )
 */
class DefaultFormattedTextHandler extends FieldHandlerBase
{
    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle, $field_name, FieldDefinitionInterface $field)
    {
        $allowed = ['text_with_summary', 'text_long'];

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

            foreach ($data as $item) {
                if (!empty($item['value'])) {
                    // Replace node links correctly.
                    $item['value'] = $this->replaceEntityReferenceLinks($item['value']);
                }
                $result[] = $item;
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
        if (!parent::push($intent)) {
            return false;
        }

        $entity = $intent->getEntity();

        $add_file_uri_dependency = function ($matches) use ($intent) {
            // PDF files can have a #page=... anchor attached that we want to keep.
            $path = preg_replace('@#.*$@', '', $matches[1]);
            $uri = 'public://'.urldecode($path);

            /** @var FileInterface[] $files */
            $files = \Drupal::entityTypeManager()
                ->getStorage('file')
                ->loadByProperties(['uri' => $uri]);

            if (!count($files)) {
                \Drupal::logger('cms_content_sync')->error(
                    'Failed to push referenced file by URI in the formatted text as the file is missing on this site: @uri<br>Flow: @flow_id | Pool: @pool_id',
                    [
                        '@uri' => $uri,
                        '@flow_id' => $intent->getFlow()->id(),
                        '@pool_id' => $intent->getPool()->id(),
                    ]
                );

                return '';
            }

            $file = reset($files);

            $intent->addDependency($file);

            return '';
        };

        $base_path = PublicStream::basePath();
        foreach ($entity->get($this->fieldName)->getValue() as $item) {
            $text = $item['value'];

            // Simple image embedding (default ckeditor + IMCE images)
            preg_replace_callback(
                '@<img[^>]+src="/'.$base_path.'/([^"]+)"@',
                $add_file_uri_dependency,
                $text
            );

            // Other file embedding (IMCE files)
            preg_replace_callback(
                '@<a[^>]+href="/'.$base_path.'/([^"]+)"@',
                $add_file_uri_dependency,
                $text
            );

            // Entity embedding (especially media)
            preg_replace_callback(
                '@<drupal-(entity|media)[^>]+data-entity-type="([^"]+)"\s+data-entity-uuid="([^"]+)"@',
                function ($matches) use ($intent) {
                    $type = $matches[2];
                    $uuid = $matches[3];

                    $entity = \Drupal::service('entity.repository')->loadEntityByUuid($type, $uuid);

                    if (!$entity) {
                        return '';
                    }

                    $intent->addDependency($entity);

                    return '';
                },
                $text
            );
        }

        return true;
    }

    /**
     * Replace all "/node/..." links with their correct ID for the current site.
     *
     * @todo If a new entity is added, we should scan the database for existing
     * references to it that can now be resolved.
     *
     * @param $text
     *
     * @return string
     */
    protected function replaceEntityReferenceLinks($text)
    {
        $entity_repository = \Drupal::service('entity.repository');

        $replace_uri_callback = function ($matches) {
            $path = $matches[2];

            // PDF files can have a #page=... anchor attached that we want to keep.
            $anchor = null;
            if (false !== strpos($path, '#')) {
                list($path, $anchor) = explode('#', $path);
            }

            $parts = explode('/', $path);
            $file = null;
            $uri = null;
            for ($i = 0; $i < count($parts); ++$i) {
                $uri = 'public://'.urldecode(implode('/', array_slice($parts, $i)));

                /** @var FileInterface[] $files */
                $files = \Drupal::entityTypeManager()
                    ->getStorage('file')
                    ->loadByProperties(['uri' => $uri]);

                if (count($files)) {
                    $file = reset($files);

                    break;
                }
            }

            if (!$file) {
                \Drupal::logger('cms_content_sync')->error(
                    'Failed to replace file URI in the formatted text as the file is missing on this site: @uri',
                    [
                        '@uri' => 'public://'.urldecode($path),
                    ]
                );

                return $matches[1].'"/404"';
            }

            $url = file_url_transform_relative(file_create_url($uri));

            if ($anchor) {
                $url .= '#'.$anchor;
            }

            return $matches[1].'"'.$url.'"';
        };

        // Simple image embedding (default ckeditor + IMCE images)
        $text = preg_replace_callback(
            '@(<img[^>]+src=)"/sites/[^/]+/files/([^"]+)"@',
            $replace_uri_callback,
            $text
        );

        // Other file embedding (IMCE files)
        $text = preg_replace_callback(
            '@(<a[^>]+href=)"/sites/[^/]+/files/([^"]+)"@',
            $replace_uri_callback,
            $text
        );

        // Entity embedding (especially media)
        return preg_replace_callback(
            '@data-entity-uuid="([0-9a-z-]+)" href="/node/([0-9]+)"@',
            function ($matches) use ($entity_repository) {
                $uuid = $matches[1];
                $id = $matches[2];

                try {
                    $node = $entity_repository->loadEntityByUuid('node', $uuid);
                    if ($node) {
                        $id = $node->id();
                    }
                } catch (\Exception $e) {
                }

                return 'data-entity-uuid="'.$uuid.'" href="/node/'.$id.'"';
            },
            $text
        );
    }
}
