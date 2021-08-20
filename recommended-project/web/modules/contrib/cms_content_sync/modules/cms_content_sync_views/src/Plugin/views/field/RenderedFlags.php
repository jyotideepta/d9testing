<?php

namespace Drupal\cms_content_sync_views\Plugin\views\field;

use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Views Field handler to check if a entity is pulled.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("cms_content_sync_rendered_flags")
 */
class RenderedFlags extends FieldPluginBase {

  const FLAG_DESCRIPTION = [
    'push_failed' => 'Last push failed (%error)',
    'push_failed_soft' => 'No push (%error)',
    'pull_failed' => 'Last pull failed (%error)',
    'pull_failed_soft' => 'No pull (%error)',
    'last_push_reset' => 'Reset: Requires push',
    'last_pull_reset' => 'Reset: Requires pull',
    'is_source_entity' => 'Created at this site',
    'edit_override' => 'Pulled and overwritten',
    'is_deleted' => 'Deleted',
    'pulled_embedded' => 'Pulled embedded',
    'pushed_embedded' => 'Pushed embedded',
  ];

  /**
   *
   */
  public static function describeFlag($name, $error = NULL) {
    $description = self::FLAG_DESCRIPTION[$name];
    if (empty($error)) {
      $description = str_replace(' (%error)', '', $description);
    }
    else {
      $description = str_replace(' (%error)', $error, $description);
    }
    return $description;
  }

  const ERROR_DESCRIPTION = [
    PushIntent::PUSH_FAILED_REQUEST_FAILED => 'Sync Core not available',
    PushIntent::PUSH_FAILED_REQUEST_INVALID_STATUS_CODE => 'invalid status code',
    PushIntent::PUSH_FAILED_INTERNAL_ERROR => 'Drupal API error',
    PushIntent::PUSH_FAILED_DEPENDENCY_PUSH_FAILED => 'dependency failed to push',
    PushIntent::PUSH_FAILED_HANDLER_DENIED => 'as configured',
    PushIntent::PUSH_FAILED_UNCHANGED => 'no changes',

    PullIntent::PULL_FAILED_DIFFERENT_VERSION => 'different version',
    PullIntent::PULL_FAILED_CONTENT_SYNC_ERROR => 'module failure',
    PullIntent::PULL_FAILED_INTERNAL_ERROR => 'Drupal API failure',
    PullIntent::PULL_FAILED_UNKNOWN_POOL => 'unknown Pool',
    PullIntent::PULL_FAILED_NO_FLOW => 'no matching Flow',
  ];

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
   * @param string $flag
   * @param array $details
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   */
  protected function renderError($flag, $details) {
    if (empty(self::FLAG_DESCRIPTION[$flag])) {
      $message = $flag . ' (%error)';
    }
    else {
      $message = self::FLAG_DESCRIPTION[$flag];
    }

    if (empty($details['error'])) {
      $error = 'unknown';
    }
    elseif (empty(self::ERROR_DESCRIPTION[$details['error']])) {
      $error = $details['error'];
    }
    else {
      $error = self::ERROR_DESCRIPTION[$details['error']];
    }

    return $this->t($message, [
      '%error' => $this->t($error),
    ]);
  }

  /**
   * @{inheritdoc}
   *
   * @param \Drupal\views\ResultRow $values
   *
   * @return \Drupal\Component\Render\MarkupInterface|TranslatableMarkup|ViewsRenderPipelineMarkup|string
   */
  public function render(ResultRow $values) {
    /**
     * @var \Drupal\cms_content_sync\Entity\EntityStatus $entity
     */
    $entity = $values->_entity;

    $flags = [
      'push_failed' => $entity->didPushFail(),
      'push_failed_soft' => $entity->didPushFail(NULL, TRUE),
      'pull_failed' => $entity->didPullFail(),
      'pull_failed_soft' => $entity->didPullFail(NULL, TRUE),
      'last_push_reset' => $entity->wasLastPushReset(),
      'last_pull_reset' => $entity->wasLastPullReset(),
      'is_source_entity' => $entity->isSourceEntity(),
      'edit_override' => $entity->isOverriddenLocally(),
      'is_deleted' => $entity->isDeleted(),
      'pulled_embedded' => $entity->wasPulledEmbedded(),
      'pushed_embedded' => $entity->wasPushedEmbedded(),
    ];

    $messages = [];
    if ($flags['push_failed']) {
      $details = $entity->getData(EntityStatus::DATA_PUSH_FAILURE);
      $messages['push_failed'] = $this->renderError('push_failed', $details);
    }
    if ($flags['push_failed_soft']) {
      $details = $entity->getData(EntityStatus::DATA_PUSH_FAILURE);
      $messages['push_failed_soft'] = $this->renderError('push_failed', $details);
    }
    if ($flags['pull_failed']) {
      $details = $entity->getData(EntityStatus::DATA_PULL_FAILURE);
      $messages['pull_failed'] = $this->renderError('pull_failed', $details);
    }
    if ($flags['pull_failed_soft']) {
      $details = $entity->getData(EntityStatus::DATA_PULL_FAILURE);
      $messages['pull_failed_soft'] = $this->renderError('pull_failed', $details);
    }
    foreach ($flags as $name => $set) {
      if (!$set || isset($messages[$name])) {
        continue;
      }
      $messages[$name] = $this->t(self::FLAG_DESCRIPTION[$name]);
    }

    $renderable = [
      '#theme' => 'rendered_flags',
      '#messages' => $messages,
      '#cache' => [
        'max-age' => 0,
      ],
    ];
    $rendered = \Drupal::service('renderer')->render($renderable);

    return $rendered;
  }

}
