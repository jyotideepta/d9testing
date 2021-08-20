<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\field_handler;

use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\user\Entity\User;
use EdgeBox\SyncCore\Interfaces\Configuration\IDefineEntityType;

/**
 * Providing a minimalistic implementation for any field type.
 *
 * @FieldHandler(
 *   id = "cms_content_sync_default_user_reference_handler",
 *   label = @Translation("Default User Reference"),
 *   weight = 80
 * )
 */
class DefaultUserReferenceHandler extends DefaultEntityReferenceHandler
{
    public const IDENTIFICATION_NAME = 'name';
    public const IDENTIFICATION_EMAIL = 'mail';
    public const IDENTIFICATION_SYNC_USER = 'sync_user';

    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle, $field_name, FieldDefinitionInterface $field)
    {
        if (!in_array($field->getType(), ['entity_reference', 'entity_reference_revisions'])) {
            return false;
        }

        $type = $field->getSetting('target_type');

        return 'user' == $type;
    }

    /**
     * {@inheritdoc}
     */
    public function getHandlerSettings($current_values, $type = 'both')
    {
        return [
            'identification' => [
                '#type' => 'select',
                '#title' => 'Identification',
                '#options' => [
                    self::IDENTIFICATION_EMAIL => 'Mail',
                    self::IDENTIFICATION_NAME => 'Name',
                    self::IDENTIFICATION_SYNC_USER => 'Sync User',
                ],
                '#default_value' => $current_values['identification'] ?? self::IDENTIFICATION_EMAIL,
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function definePropertyAtType(IDefineEntityType $type_definition)
    {
        $type_definition->addObjectProperty($this->fieldName, $this->fieldDefinition->getLabel(), true, $this->fieldDefinition->isRequired());
    }

    /**
     * {@inheritdoc}
     */
    protected function forceMergeOverwrite()
    {
        return 'revision_uid' == $this->fieldName;
    }

    /**
     * {@inheritdoc}
     */
    protected function loadReferencedEntity(PullIntent $intent, $definition)
    {
        $property = $this->settings['handler_settings']['identification'];

        if (self::IDENTIFICATION_SYNC_USER == $property) {
            $uid = \Drupal::service('keyvalue.database')->get('cms_content_sync_user')->get('uid');

            return User::load($uid);
        }

        if (empty($definition[$property])) {
            return null;
        }

        /**
         * @var \Drupal\user\Entity\User[] $entities
         */
        $entities = \Drupal::entityTypeManager()
            ->getStorage('user')
            ->loadByProperties([$property => $definition[$property]]);

        return reset($entities);
    }

    /**
     * {@inheritdoc}
     */
    protected function serializeReference(PushIntent $intent, EntityInterface $reference, $value)
    {
        return [
            self::IDENTIFICATION_EMAIL => $reference->get('mail')->value,
            self::IDENTIFICATION_NAME => $reference->get('name')->value,
        ];
    }
}
