<?php

namespace Drupal\cms_content_sync_developer\Form;

use Drupal\cms_content_sync\Entity\Flow;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Content Sync Expert Flow creation form.
 */
class FlowFormExpert extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'cms_content_sync_developer.add_flow_expert';
  }

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return array
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#maxlength' => 255,
      '#description' => $this->t("An administrative name describing the workflow intended to be achieved with this synchronization."),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#machine_name' => [
        'exists' => [$this, 'exists'],
        'source' => ['name'],
      ],
    ];

    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Flow type'),
      '#options' => [
        'push' => $this->t('Push'),
        'pull' => $this->t('Pull'),
      ],
      '#required' => TRUE,
    ];

    $description = 'One configuration per line separated by "|": <i>entity_type|bundle|pool|pool_usage|behavior</i>. <br>Example configuration: <br>
      <ul>
        <li>node|basic_page|content|force|automatically</li>
        <li>node|page|content|force|manually</li>
        <li>taxonomy_term|tags|content|allow|dependency</li>
      </ul>';

    $form['configuration'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Configuration'),
      '#required' => TRUE,
      '#rows' => 20,
      '#description' => $description,
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create flow'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $configurations = $form_state->getValue('configuration');
    $configurations = preg_split("/\r\n|\n|\r/", $configurations);
    $flow_config = [];
    if (is_array($configurations)) {
      foreach ($configurations as &$config) {
        $config = explode('|', $config);
      }

      foreach ($configurations as $key => $item) {
        if ($form_state->getValue('type') == 'pull') {
          $item = [
            'entity_type' => $item[0],
            'bundle' => $item[1],
            'import_configuration' => [
              'behavior' => $item[3],
              'import_deletion' => TRUE,
              'allow_local_deletion_of_import' => TRUE,
              'import_updates' => 'force_and_forbid_editing',
              'import_pools' => [
                $item[2] => $item[3],
              ],
            ],
          ];
        }

        if ($form_state->getValue('type') == 'push') {
          $item = [
            'entity_type' => $item[0],
            'bundle' => $item[1],
            'export_configuration' => [
              'behavior' => $item[4],
              'export_deletion_settings' => TRUE,
              'export_pools' => [
                $item[2] => $item[3],
              ],
            ],
          ];
        }

        $flow_config[$item['entity_type']][$item['bundle']] = $item;
      }

      $flow = Flow::createFlow($form_state->getValue('name'), $form_state->getValue('id'), TRUE, [], $flow_config);

      // Redirect user to flow form.
      $route_paramenters = [
        'cms_content_sync_flow' => $flow,
      ];

      $form_state->setRedirect('entity.cms_content_sync_flow.edit_form', $route_paramenters);

    }
    else {
      // Something went wrong.
      // @todo .
    }

  }

}
