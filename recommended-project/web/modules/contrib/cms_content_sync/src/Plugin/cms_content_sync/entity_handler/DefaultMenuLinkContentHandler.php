<?php

namespace Drupal\cms_content_sync\Plugin\cms_content_sync\entity_handler;

use Drupal\cms_content_sync\Plugin\EntityHandlerBase;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use EdgeBox\SyncCore\V1\Entity\Entity;

/**
 * Class DefaultMenuLinkContentHandler, providing a minimalistic implementation
 * for menu items, making sure they're referenced correctly by UUID.
 *
 * @EntityHandler(
 *   id = "cms_content_sync_default_menu_link_content_handler",
 *   label = @Translation("Default Menu Link Content"),
 *   weight = 100
 * )
 */
class DefaultMenuLinkContentHandler extends EntityHandlerBase
{
    public const USER_REVISION_PROPERTY = 'revision_user';

    protected $resolveDependent;

    /**
     * {@inheritdoc}
     */
    public static function supports($entity_type, $bundle)
    {
        return 'menu_link_content' == $entity_type;
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedPreviewOptions()
    {
        return [
            'table' => 'Table',
        ];
    }

    /**
     * @param \EdgeBox\SyncCore\Interfaces\Configuration\IDefineEntityType $definition
     */
    public function updateEntityTypeDefinition(&$definition)
    {
        parent::updateEntityTypeDefinition($definition);

        $module_handler = \Drupal::service('module_handler');
        if ($module_handler->moduleExists('menu_token')) {
            $definition->addObjectProperty('menu_token_options', 'Menu token options', false);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getHandlerSettings($current_values, $type = 'both')
    {
        $menus = menu_ui_get_menus();

        return [
            'ignore_unpublished' => [
                '#type' => 'checkbox',
                '#title' => 'Ignore disabled',
                '#default_value' => isset($current_values['ignore_unpublished']) && 0 === $current_values['ignore_unpublished'] ? 0 : 1,
            ],
            'restrict_menus' => [
                '#type' => 'checkboxes',
                '#title' => 'Restrict to menus',
                '#default_value' => isset($current_values['restrict_menus']) ? $current_values['restrict_menus'] : [],
                '#options' => $menus,
                '#description' => t('When no checkbox is set, menu items from all menus will be pushed/pulled.'),
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function push(PushIntent $intent, EntityInterface $entity = null)
    {
        $result = parent::push($intent, $entity);

        if ($result && SyncIntent::ACTION_DELETE != $intent->getAction()) {
            $module_handler = \Drupal::service('module_handler');

            if ($module_handler->moduleExists('menu_token')) {
                $uuid = $intent->getUuid();
                $config_menu = \Drupal::entityTypeManager()
                    ->getStorage('link_configuration_storage')
                    ->load($uuid);

                if (!empty($config_menu)) {
                    $config_array = unserialize($config_menu->get('configurationSerialized'));
                    $intent->setProperty('menu_token_options', $config_array);
                }
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function ignorePull(PullIntent $intent)
    {
        $action = $intent->getAction();
        if (SyncIntent::ACTION_DELETE == $action) {
            return parent::ignorePull($intent);
        }

        if (empty($this->resolveDependent)) {
            if (empty($intent->getProperty('enabled'))) {
                $enabled = true;
            } else {
                $enabled = $intent->getProperty('enabled')[0]['value'];
            }
        } else {
            $enabled = $this->resolveDependent['data']['enabled'];
        }

        // Not published? Ignore this revision then.
        if (!$enabled && $this->settings['handler_settings']['ignore_unpublished']) {
            // Unless it's a delete, then it won't have a status and is independent.
            return true;
        }

        if ($this->shouldRestrictMenuUsage()) {
            $menu = $intent->getProperty('menu_name')[0]['value'];
            if (empty($this->settings['handler_settings']['restrict_menus'][$menu])) {
                return true;
            }
        }

        return parent::ignorePull($intent);
    }

    /**
     * {@inheritdoc}
     */
    public function pull(PullIntent $intent)
    {
        $link = $intent->getProperty('link');

        if (isset($link[0]['uri'])) {
            $uri = $link[0]['uri'];
            preg_match('@^internal:/([a-z_0-9]+)\/([a-z0-9-]+)$@', $uri, $found);

            if (!empty($found)) {
                $referenced = \Drupal::service('entity.repository')
                    ->loadEntityByUuid($found[1], $found[2]);

                if (!$referenced) {
                    $this->resolveDependent = [
                        Entity::ENTITY_TYPE_KEY => $found[1],
                        Entity::UUID_KEY => $found[2],
                        'data' => [
                            'enabled' => (bool) $intent->getProperty('enabled')[0]['value'],
                        ],
                    ];

                    $intent->overwriteProperty('enabled', [['value' => 0]]);
                }
            }
        } elseif (!empty($link[0][Entity::ENTITY_TYPE_KEY]) && !empty($link[0][Entity::UUID_KEY])) {
            $referenced = $intent->loadEmbeddedEntity($link[0]);

            if (!$referenced) {
                $this->resolveDependent = array_merge($link[0], [
                    'data' => [
                        'enabled' => (bool) $intent->getProperty('enabled')[0]['value'],
                    ],
                ]);

                $intent->overwriteProperty('enabled', [['value' => 0]]);
            }
        }

        if (!parent::pull($intent)) {
            return false;
        }

        if ($this->resolveDependent) {
            $intent->saveUnresolvedDependency($this->resolveDependent, 'link', $this->resolveDependent['data']);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function ignorePush(PushIntent $intent)
    {
        /**
         * @var \Drupal\menu_link_content\Entity\MenuLinkContent $entity
         */
        $entity = $intent->getEntity();

        if (!$entity->isEnabled() && $this->settings['handler_settings']['ignore_unpublished']) {
            return true;
        }

        if ($this->shouldRestrictMenuUsage()) {
            $menu = $entity->getMenuName();
            if (empty($this->settings['handler_settings']['restrict_menus'][$menu])) {
                return true;
            }
        }

        $uri = $entity->get('link')->getValue()[0]['uri'];
        if ('entity:' == substr($uri, 0, 7)) {
            preg_match('/^entity:(.*)\/(\d*)$/', $uri, $found);
            // This means we're already dealing with a UUID that has not been resolved
            // locally yet. So there's no sense in pushing this back to the pool.
            if (empty($found)) {
                return true;
            }

            $link_entity_type = $found[1];
            $link_entity_id = $found[2];
            $entity_manager = \Drupal::entityTypeManager();
            $reference = $entity_manager->getStorage($link_entity_type)
                ->load($link_entity_id);
            // Dead reference > ignore.
            if (empty($reference)) {
                return true;
            }
        }

        return parent::ignorePush($intent);
    }

    /**
     * {@inheritdoc}
     */
    protected function setEntityValues(PullIntent $intent, FieldableEntityInterface $entity = null)
    {
        $result = parent::setEntityValues($intent, $entity);

        if (SyncIntent::ACTION_DELETE != $intent->getAction()) {
            $module_handler = \Drupal::service('module_handler');

            if ($module_handler->moduleExists('menu_token')) {
                $config_array = $intent->getProperty('menu_token_options');
                if (!empty($config_array)) {
                    $uuid = $intent->getUuid();
                    $config_menu = \Drupal::entityTypeManager()
                        ->getStorage('link_configuration_storage')
                        ->load($uuid);
                    if (empty($config_menu)) {
                        $config_menu = \Drupal::entityTypeManager()
                            ->getStorage('link_configuration_storage')
                            ->create([
                                'id' => $uuid,
                                'label' => 'Menu token link configuration',
                                'linkid' => (string) $intent->getProperty('link')[0]['uri'],
                                'configurationSerialized' => serialize($config_array),
                            ]);
                    } else {
                        $config_menu->set('linkid', (string) $intent->getProperty('link')[0]['uri']);
                        $config_menu->set('configurationSerialized', serialize($config_array));
                    }
                    $config_menu->save();
                }
            }
        }

        return $result;
    }

    protected function shouldRestrictMenuUsage()
    {
        return !empty(array_diff(array_values($this->settings['handler_settings']['restrict_menus']), [0]));
    }
}
