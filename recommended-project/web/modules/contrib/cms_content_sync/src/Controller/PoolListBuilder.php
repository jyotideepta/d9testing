<?php

namespace Drupal\cms_content_sync\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Link;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a listing of Pool.
 */
class PoolListBuilder extends ConfigEntityListBuilder
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
     * @param \Drupal\Core\State\MessengerInterface      $messenger
     *                                                                   The core messenger interface
     */
    public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $storage, ConfigFactoryInterface $config_factory, MessengerInterface $messenger)
    {
        parent::__construct($entity_type, $storage);
        $this->configFactory = $config_factory;
        $this->messenger = $messenger;
    }

    /**
     * {@inheritdoc}
     */
    public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type)
    {
        return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('config.factory'),
      $container->get('messenger')
    );
    }

    /**
     * {@inheritdoc}
     */
    public function buildHeader()
    {
        $header['name'] = $this->t('Name');
        $header['id'] = $this->t('Machine name');
        $header['backend_url'] = $this->t('Sync Core');

        return $header + parent::buildHeader();
    }

    /**
     * {@inheritdoc}
     */
    public function buildRow(EntityInterface $entity)
    {
        /**
         * @var \Drupal\cms_content_sync\Entity\Pool $entity
         */
        $row['name'] = $entity->label();
        $row['id'] = $entity->id();
        $row['backend_url'] = $entity->getSyncCoreUrl();

        // Check of overwrites.
        $config = $this->configFactory->get('cms_content_sync.pool.'.$entity->id());

        $overwritten_backend_url = $config->get('backend_url') != $entity->getSyncCoreUrl();

        if ($overwritten_backend_url) {
            $row['backend_url'] .= ' <i>('.$this->t('Overwritten').')</i>';
        }

        $url = parse_url($row['backend_url']);

        $row['backend_url'] = new FormattableMarkup($url['host'], []);

        return $row + parent::buildRow($entity);
    }

    public function render()
    {
        $build = parent::render();
        $overwritten = false;
        $rows = $build['table']['#rows'];

        // Check if the site has already been registered.
        $settings = ContentSyncSettings::getInstance();
        if (Migration::alwaysUseV2() && !$settings->getSiteUuid()) {
            $link = Link::fromTextAndUrl($this->t('registered'), Url::fromRoute('cms_content_sync.site'))->toString();
            $this->messenger()->addWarning($this->t('The site needs to be @link before creating a pool.', ['@link' => $link]));
        }

        if (!empty($rows)) {
            foreach ($rows as $row) {
                if (strpos($row['backend_url']->__toString(), 'Overwritten')) {
                    $overwritten = true;
                }
            }

            if ($overwritten) {
                $build['explanation_overwritten'] = [
                    '#markup' => '<i>'.$this->t('Overwritten: This configuration has been overwritten within your settings.php file.').'</i><br>',
                ];
            }

            $build['hints'] = [
                '#markup' => '<h3>'.$this->t('Hints').'</h3>'.$this->t('You can overwrite parts of your pool configuration using your settings.php file:'),
            ];

            $overwrite_sync_core_url_example = '<li>'.$this->t('Sync Core URL: <i>@overwrite_sync_core_url</i>', ['@overwrite_sync_core_url' => '$settings["cms_content_sync"]["pools"][<pool_machine_name>]["backend_url"] = "http://example.com";']).'</li>';

            $build['hints_examples'] = [
                '#markup' => '<ul>'.$overwrite_sync_core_url_example.'</ul>',
            ];
        }

        return $build;
    }
}
