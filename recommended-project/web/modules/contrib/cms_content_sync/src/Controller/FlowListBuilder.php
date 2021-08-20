<?php

namespace Drupal\cms_content_sync\Controller;

use Drupal\cms_content_sync\Entity\Flow;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a listing of Flow.
 */
class FlowListBuilder extends ConfigEntityListBuilder
{
    /**
     * Constructs a new EntityListBuilder object.
     *
     * @param \Drupal\Core\Entity\EntityTypeInterface    $entity_type
     *                                                                   The entity type definition
     * @param \Drupal\Core\Entity\EntityStorageInterface $storage
     *                                                                   The entity storage class
     * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
     *                                                                   The config factory
     */
    public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $storage, ConfigFactoryInterface $config_factory)
    {
        parent::__construct($entity_type, $storage);
        $this->configFactory = $config_factory;
    }

    /**
     * {@inheritdoc}
     */
    public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type)
    {
        return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('config.factory')
    );
    }

    /**
     * {@inheritdoc}
     */
    public function buildHeader()
    {
        $header['name'] = $this->t('Synchronization');
        $header['id'] = $this->t('Machine name');
        $header['status'] = $this->t('Status');

        return $header + parent::buildHeader();
    }

    /**
     * {@inheritdoc}
     */
    public function buildRow(EntityInterface $entity)
    {
        /**
         * @var \Drupal\cms_content_sync\Entity\Flow $entity
         */
        $row['name'] = $entity->label();
        $row['id'] = $entity->id();

        $config = $this->configFactory->get('cms_content_sync.flow.'.$entity->id());
        $overwritten = $config->get('status') != $entity->status();

        $active = $overwritten ? !$entity->status() : $entity->status();
        $status = $active ? $this->t('Active') : $this->t('Inactive');

        if ($overwritten) {
            $status .= ' <i>('.$this->t('Overwritten').')</i>';
        }

        if ($active) {
            // Show version mismatch warning.
            $entity_type_configs = $entity->getEntityTypeConfig(null, null, true);
            // Get version from config.
            $flow_config = \Drupal::config('cms_content_sync.flow.'.$entity->id());
            $version_mismatch = false;
            foreach ($entity_type_configs as $config_key => $entity_type_config) {
                $entity_type = $entity_type_config['entity_type_name'];
                $bundle = $entity_type_config['bundle_name'];

                // Get active version.
                $active_version = Flow::getEntityTypeVersion($entity_type, $bundle);

                // Get config version.
                $config_version = $flow_config->get('sync_entities.'.$config_key.'.version');
                if ($active_version != $config_version) {
                    $version_mismatch = true;

                    break;
                }
            }
            if ($version_mismatch) {
                $status .= ' <i>('.$this->t('Version mismatch').')</i>';
            }
        }

        $row['status'] = new FormattableMarkup($status, []);

        return $row + parent::buildRow($entity);
    }

    public function render()
    {
        $build = parent::render();
        $status_overwritten = false;
        $version_mismatch = false;
        $rows = $build['table']['#rows'];

        // Check if the site has already been registered.
        $settings = ContentSyncSettings::getInstance();
        if (Migration::alwaysUseV2() && !$settings->getSiteUuid()) {
            $link = Link::fromTextAndUrl($this->t('registered'), Url::fromRoute('cms_content_sync.site'))->toString();
            $this->messenger()->addWarning($this->t('The site needs to be @link before creating a flow.', ['@link' => $link]));
        }

        if (!empty($rows)) {
            foreach ($rows as $row) {
                if (strpos($row['status']->__toString(), 'Overwritten')) {
                    $status_overwritten = true;
                }
                if (strpos($row['status']->__toString(), 'Version mismatch')) {
                    $version_mismatch = true;
                }
            }

            if ($status_overwritten) {
                $build['explanation_overwritten'] = [
                    '#markup' => '<i>'.$this->t('Overwritten: The status of this flow has been overwritten in a settings file.').'</i><br>',
                ];
            }
            if ($version_mismatch) {
                $build['explanation_version_mismatch'] = [
                    '#markup' => '<i>'.$this->t('Version mismatch: The flow contains mismatching bundle versions and needs to be re-saved and exported.').'</i>',
                ];
            }

            $build['hints'] = [
                '#markup' => '<h3>'.$this->t('Hints').'</h3>'.$this->t('You can enable / disable Flows using your settings.php file:'),
            ];

            $overwrite_status = '<li>'.$this->t('Status: <i>@overwrite_status</i>', ['@overwrite_status' => '$config["cms_content_sync.flow.<flow_machine_name>"]["status"] = FALSE;']).'</li>';

            $build['hints_examples'] = [
                '#markup' => '<ul>'.$overwrite_status.'</ul>',
            ];
        }

        return $build;
    }
}
