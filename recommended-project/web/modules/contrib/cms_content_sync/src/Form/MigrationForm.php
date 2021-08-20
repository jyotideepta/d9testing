<?php

namespace Drupal\cms_content_sync\Form;

use Drupal\cms_content_sync\Controller\ContentSyncSettings;
use Drupal\cms_content_sync\Controller\Migration;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Content Sync general settings form.
 */
class MigrationForm extends FormBase
{
    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'migration_form';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        // TODO: Drupal: Recreate Content Sync user password during migration to use stronger 64 characters and also ensure that the old Sync Core can't push updates anylonger; so this must be the last step when switching over.

        $settings = ContentSyncSettings::getInstance();

        // Already registered if this exists.
        $uuid = $settings->getSiteUuid();

        if ($uuid) {
            //$form['#prefix'] = '<div style="display: none;">';
            //$form['#suffix'] = '</div>';
            $form['#attributes']['style'][] = 'display: none;';

            $all_pools = Pool::getAll();
            $pool_options = [];
            foreach ($all_pools as $pool) {
                $pool_options[$pool->id()] = $pool->label();
            }
            $form['export-pools'] = [
                '#prefix' => '<div id="content-sync-migration-export-pools">',
                '#suffix' => '</div>',
                '#title' => 'Export pools',
                '#type' => 'checkboxes',
                '#options' => $pool_options,
            ];

            $all_flows = Flow::getAll();
            $flow_options = [];
            foreach ($all_flows as $flow) {
                $flow_options[$flow->id()] = $flow->label();
            }
            $form['export-flows'] = [
                '#prefix' => '<div id="content-sync-migration-export-flows">',
                '#suffix' => '</div>',
                '#title' => 'Export flows',
                '#type' => 'checkboxes',
                '#options' => $flow_options,
            ];

            $form['skip-flows-test'] = [
                '#prefix' => '<div id="content-sync-migration-skip-flows-test">',
                '#suffix' => '</div>',
                '#title' => 'Skip flows test',
                '#type' => 'checkboxes',
                '#options' => $flow_options,
            ];

            $form['skip-flows-push'] = [
                '#prefix' => '<div id="content-sync-migration-skip-flows-push">',
                '#suffix' => '</div>',
                '#title' => 'Skip flows migration',
                '#type' => 'checkboxes',
                '#options' => $flow_options,
            ];

            $form['skip-flows-pull'] = [
                '#prefix' => '<div id="content-sync-migration-skip-flows-pull">',
                '#suffix' => '</div>',
                '#title' => 'Skip flows migration',
                '#type' => 'checkboxes',
                '#options' => $flow_options,
            ];

            $form['action'] = [
                '#title' => 'Action',
                '#type' => 'radios',
                '#options' => [
                    'export-pools' => 'Export Pools',
                    'export-flows' => 'Export Flows',
                    'skip-flows-test' => 'Skip Flows test',
                    'skip-flows-push' => 'Skip Flows push',
                    'skip-flows-pull' => 'Skip Flows pull',
                    'switch' => 'Switch',
                ],
            ];

            $form['submit'] = [
                '#type' => 'submit',
                '#value' => 'Submit',
                '#button_type' => 'primary',
            ];
        } else {
            $url = Url::fromRoute('cms_content_sync_flow.site')->toString();
            \Drupal::messenger()->addMessage('Please register this site first.', 'warning');
            $form['redirect'] = [
                '#markup' => 'This site is not registered yet. Please <a href="'.$url->toString().'">register first</a>.',
            ];
        }

        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        parent::validateForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        if ('export-pools' === $form_state->getValue('action')) {
            Migration::runPoolExport(array_keys(array_filter($form_state->getValue('export-pools'))));
        } elseif ('export-flows' === $form_state->getValue('action')) {
            Migration::runFlowExport(array_keys(array_filter($form_state->getValue('export-flows'))));
        } elseif ('skip-flows-test' === $form_state->getValue('action')) {
            Migration::skipFlowsTest(array_keys(array_filter($form_state->getValue('skip-flows-test'))));
        } elseif ('skip-flows-push' === $form_state->getValue('action')) {
            Migration::skipFlowsPush(array_keys(array_filter($form_state->getValue('skip-flows-push'))));
        } elseif ('skip-flows-pull' === $form_state->getValue('action')) {
            Migration::skipFlowsPull(array_keys(array_filter($form_state->getValue('skip-flows-pull'))));
        } elseif ('switch' === $form_state->getValue('action')) {
            Migration::runSwitch();
        }
    }
}
