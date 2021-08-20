<?php

namespace Drupal\webhook\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for the webhook entity edit forms.
 */
class WebhookForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {

    $entity = $this->getEntity();
    $result = $entity->save();
    $link = $entity->toLink($this->t('View'))->toRenderable();

    $message_arguments = ['%label' => $this->entity->label()];
    $logger_arguments = $message_arguments + ['link' => render($link)];

    if ($result == SAVED_NEW) {
      $this->messenger()->addStatus($this->t('New webhook %label has been created.', $message_arguments));
      $this->logger('webhook')->notice('Created new webhook %label', $logger_arguments);
    }
    else {
      $this->messenger()->addStatus($this->t('The webhook %label has been updated.', $message_arguments));
      $this->logger('webhook')->notice('Updated new webhook %label.', $logger_arguments);
    }

    $form_state->setRedirect('entity.webhook.canonical', ['webhook' => $entity->id()]);
  }

}
