<?php

namespace Drupal\cms_content_sync_views\Plugin\views\filter;

use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync_views\Plugin\views\field\RenderedFlags;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\filter\InOperator;
use Drupal\views\ViewExecutable;

/**
 * Provides a view filter to filter on the sync state entity.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("cms_content_sync_flags_filter")
 */
class Flags extends InOperator implements ContainerFactoryPluginInterface {

  /**
   * @var base_field
   */
  protected $base_field;

  /**
   * {@inheritdoc}
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, array &$options = NULL) {
    parent::init($view, $display, $options);
    $this->base_field = $view->storage->get('base_field');
  }

  /**
   * {@inheritdoc}
   */
  public function getValueOptions() {
    if (!isset($this->valueOptions)) {
      $this->valueTitle = $this->t('Flags');
      $this->valueOptions = [
        'push_failed' => $this->t(RenderedFlags::describeFlag('push_failed')),
        'push_failed_soft' => $this->t(RenderedFlags::describeFlag('push_failed_soft')),
        'pull_failed_soft' => $this->t(RenderedFlags::describeFlag('pull_failed_soft')),
        'pull_failed' => $this->t(RenderedFlags::describeFlag('pull_failed')),
        'last_push_reset' => $this->t(RenderedFlags::describeFlag('last_push_reset')),
        'last_pull_reset' => $this->t(RenderedFlags::describeFlag('last_pull_reset')),
        'is_source_entity' => $this->t(RenderedFlags::describeFlag('is_source_entity')),
        'edit_override' => $this->t(RenderedFlags::describeFlag('edit_override')),
        'is_deleted' => $this->t(RenderedFlags::describeFlag('is_deleted')),
      ];
    }
    return $this->valueOptions;
  }

  /**
   *
   */
  public function operators() {
    $operators = [
      'is' => [
        'title' => $this->t('Is'),
        'short' => $this->t('is'),
        'short_single' => $this->t('='),
        'method' => 'opSimple',
        'values' => 1,
      ],
    ];

    return $operators;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $values = $this->value;
    $flags = 0;

    $flag_name_to_value = [
      'push_failed' => EntityStatus::FLAG_PUSH_FAILED,
      'push_failed_soft' => EntityStatus::FLAG_PUSH_FAILED_SOFT,
      'pull_failed_soft' => EntityStatus::FLAG_PULL_FAILED_SOFT,
      'pull_failed' => EntityStatus::FLAG_PULL_FAILED,
      'last_push_reset' => EntityStatus::FLAG_LAST_PUSH_RESET,
      'last_pull_reset' => EntityStatus::FLAG_LAST_PULL_RESET,
      'is_source_entity' => EntityStatus::FLAG_IS_SOURCE_ENTITY,
      'edit_override' => EntityStatus::FLAG_EDIT_OVERRIDE,
      'is_deleted' => EntityStatus::FLAG_DELETED,
    ];

    foreach ($values as $value) {
      $flags |= $flag_name_to_value[$value];
    }

    $this->query->addWhereExpression($this->options['group'], '(cms_content_sync_entity_status.flags &' . $flags . ')> 0');
  }

}
