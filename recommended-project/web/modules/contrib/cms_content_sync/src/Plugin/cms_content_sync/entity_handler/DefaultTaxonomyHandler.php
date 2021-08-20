<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\entity_handler;

use Drupal\cms_content_sync\Exception\SyncException;
use Drupal\cms_content_sync\Plugin\EntityHandlerBase;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Entity\EntityInterface;

/**
 * Class DefaultTaxonomyHandler.
 *
 * @EntityHandler(
 *   id = "cms_content_sync_default_taxonomy_handler",
 *   label = @Translation("Default Taxonomy"),
 *   weight = 90
 * )
 */
class DefaultTaxonomyHandler extends EntityHandlerBase
{
    public const MAP_BY_LABEL_SETTING = 'map_by_label';

    public const USER_REVISION_PROPERTY = 'revision_user';

    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle)
    {
        return 'taxonomy_term' == $entity_type;
    }

    /**
     * {@inheritdoc}
     */
    public function getHandlerSettings($current_values, $type = 'both')
    {
        $options = parent::getHandlerSettings($current_values, $type);

        if ('push' !== $type) {
            $options[self::MAP_BY_LABEL_SETTING] = [
                '#type' => 'checkbox',
                '#title' => 'Map by name',
                '#default_value' => isset($current_values[self::MAP_BY_LABEL_SETTING]) ? $current_values[self::MAP_BY_LABEL_SETTING] : ($this->shouldMapByLabel() ? 1 : 0),
            ];
        }

        return $options;
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
     * @param \EdgeBox\SyncCore\Interfaces\Configuration\IDefineEntityType $definition
     */
    public function updateEntityTypeDefinition(&$definition)
    {
        parent::updateEntityTypeDefinition($definition);

        $definition->addReferenceProperty('parent', 'Parent', false);
    }

    /**
     * {@inheritdoc}
     */
    public function getForbiddenFields()
    {
        return array_merge(
            parent::getForbiddenFields(),
            [
                'parent',
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function pull(PullIntent $intent)
    {
        $action = $intent->getAction();

        if ($this->ignorePull($intent)) {
            return false;
        }

        /**
         * @var \Drupal\Core\Entity\FieldableEntityInterface $entity
         */
        $entity = $intent->getEntity();

        if (SyncIntent::ACTION_DELETE == $action) {
            if ($entity) {
                return $this->deleteEntity($entity);
            }

            return false;
        }

        if (!$entity) {
            $entity_type = \Drupal::entityTypeManager()->getDefinition($intent->getEntityType());

            $label_property = $entity_type->getKey('label');
            if ($this->shouldMapByLabel()) {
                $existing = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
                    $label_property => $intent->getOperation()->getName(),
                ]);
                $existing = reset($existing);

                if (!empty($existing)) {
                    return true;
                }
            }

            $base_data = [
                $entity_type->getKey('bundle') => $intent->getBundle(),
                $label_property => $intent->getOperation()->getName(),
            ];

            $base_data[$entity_type->getKey('uuid')] = $intent->getUuid();

            $storage = \Drupal::entityTypeManager()->getStorage($intent->getEntityType());
            $entity = $storage->create($base_data);

            if (!$entity) {
                throw new SyncException(SyncException::CODE_ENTITY_API_FAILURE);
            }

            $intent->setEntity($entity);
        }

        $parent_reference = $intent->getProperty('parent');
        if ($parent_reference && ($parent = $intent->loadEmbeddedEntity($parent_reference))) {
            $entity->set('parent', ['target_id' => $parent->id()]);
        } else {
            $entity->set('parent', ['target_id' => 0]);
            if (!empty($parent_reference)) {
                $intent->saveUnresolvedDependency($parent_reference, 'parent');
            }
        }

        if (!$this->setEntityValues($intent)) {
            return false;
        }

        return true;
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

        $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
        $parents = $term_storage->loadParents($entity->id());

        if (count($parents)) {
            $parent_term = reset($parents);
            $parent = $intent->addDependency($parent_term);
            $intent->setProperty('parent', $parent);
        }

        // Since taxonomy terms ain't got a created date, we set the changed
        // date instead during the first push.
        $status_entity = $intent->getEntityStatus();
        if (is_null($status_entity->getLastPush())) {
            $this->setDateProperty($intent, 'created', (int) $entity->getChangedTime());
        }

        return true;
    }

    /**
     * If set, terms will not be pulled if an identical term already exists. Instead, this term will be mapped when
     * pulling content that references it.
     */
    protected function shouldMapByLabel()
    {
        return isset($this->settings['handler_settings'][self::MAP_BY_LABEL_SETTING]) && 1 == $this->settings['handler_settings'][self::MAP_BY_LABEL_SETTING];
    }
}
