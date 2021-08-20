<?php

namespace Drupal\cms_content_sync_views\Plugin\views\field;

use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Views Field handler to check if a entity is pulled.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("cms_content_sync_sync_state")
 */
class SyncState extends FieldPluginBase {

  /**
   * @{inheritdoc}
   */
  public function query() {
    // Leave empty to avoid a query on this field.
  }

  /**
   * Define the available options.
   *
   * @return array
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    return $options;
  }

  /**
   * Provide the options form.
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
  }

  /**
   * @{inheritdoc}
   *
   * @param \Drupal\views\ResultRow $values
   *
   * @return \Drupal\Component\Render\MarkupInterface|TranslatableMarkup|ViewsRenderPipelineMarkup|string
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function render(ResultRow $values) {
    $entity = $values->_entity;
    $messages = [];
    // Get the status entity.
    $entity_status = EntityStatus::getInfosForEntity($entity->getEntityTypeId(), $entity->uuid());

    // @todo Refactor to have rending of output in separated methods to allow
    // overwriting per entity type.
    foreach ($entity_status as $status) {
      // Take care of translations.
      $entity_type_id = $entity->getEntityTypeId();
      $field_data_langcode = empty($values->{$entity_type_id . '_field_data_langcode'}) ? NULL : $values->{$entity_type_id . '_field_data_langcode'};
      if (!is_null($field_data_langcode)) {
        $entity = $entity->getTranslation($field_data_langcode);
      }

      $pool = $status->getPool();

      // "Pulled from ... at ..."
      // We are checking on LastPull and not on the source entity
      // since we also will have an "pulled" date when it comes to cross sync.
      if (!is_null($status->getLastPull())) {
        $source_url = $status->getSourceUrl();
        if (!is_null($source_url)) {
          $url = Url::fromUri($source_url);
          $link = Link::fromTextAndUrl(t('here'), $url);
          $link = $link->toRenderable();
          $link['#attributes'] = ['target' => '_blank'];
          $link_render = render($link);

          $messages['pulled_from'] = $this->t('Pulled from @link (@pool) at @date.', [
            '@pool' => $pool ? $pool->label() : $status->get('pool')->value,
            '@link' => $link_render,
            '@date' => date('d.m.Y - H:i', $status->getLastPull()),
          ]);
        }
      }

      // "Pushed on ... to ...".
      if ($entity->getEntityTypeId() == 'redirect') {
        $created = $entity->getCreated();
      }
      else {
        $created = $entity->getCreatedTime();
      }

      if (!is_null($status->getLastPush()) && $status->getLastPush() >= $created) {
        $show_usage_route_parameters = [
          'entity' => $status->getEntity()->id(),
          'entity_type' => $status->getEntity()->getEntityTypeId(),
        ];

        $show_usage_url = Url::fromRoute(
          'cms_content_sync.show_usage',
          $show_usage_route_parameters,
          [
            'attributes' => [
              'class' => ['use-ajax'],
              'data-dialog-type' => 'modal',
              'data-dialog-options' => Json::encode([
                'width' => 700,
              ]),
            ],
          ]
        );
        $show_usage = Link::fromTextAndUrl(t('Show usage'), $show_usage_url);
        $show_usage = $show_usage->toRenderable();

        $messages['pushed_on'] = $this->t('Pushed on @date to @pool - @show_usage.', [
          '@date' => date('d.m.Y - H:i', $status->getLastPush()),
          '@pool' => $pool ? $pool->label() : $status->get('pool')->value,
          '@show_usage' => render($show_usage),
        ]);
      }

      // @todo "Will be pushed to ..."
      //   if ($status->isSourceEntity() && is_null($status->getLastPush())) {
      //        $messages .= $this->t('Will be pushed to @pools', [
      //          '@pools' => $pools
      //        ]);
      //      }
      // "Update waiting to be pushed.".
      // The entity type redirect provided by the redirect module (https://www.drupal.org/project/redirect)
      // does not have a changed date.
      if ($entity->getEntityTypeId() != 'redirect' && !is_null($status->getLastPush()) && $status->getLastPush() < $entity->getChangedTime()) {
        $push_changes_route_parameters = [
          'flow_id' => $status->getFlow()->id(),
          'entity' => $status->getEntity()->id(),
          'entity_type' => $status->getEntity()->getEntityTypeId(),
        ];
        $push_changes_url = Url::fromRoute(
          'cms_content_sync.publish_changes',
          $push_changes_route_parameters,
          ['query' => ['destination' => Url::fromRoute('<current>')->toString()]]
        );
        $push_changes = Link::fromTextAndUrl(t('Push changes'), $push_changes_url);
        $push_changes = $push_changes->toRenderable();

        $messages['update_waiting'] = $this->t('Update waiting to be pushed - @push_changes.', [
          '@push_changes' => render($push_changes),
        ]);
      }

      // "Overridden locally".
      if ($status->isOverriddenLocally()) {
        $messages['overridden_locally'] = $this->t('Overridden locally.');
      }
    }

    if(empty($messages)) {
      $messages['not_syndicated'] = $this->t('<em>Not pushed or pulled yet.</em>');
    } else {
      $link = Link::createFromRoute('Sync status', 'cms_content_sync.content_sync_status', ['node' => $entity->id()])->toString();
      $messages['view_status'] = $link;
    }

    $renderable = [
      '#theme' => 'sync_status',
      '#messages' => $messages,
      '#cache' => [
        'max-age' => 0,
      ],
    ];
    $rendered = \Drupal::service('renderer')->render($renderable);

    return $rendered;
  }

}
