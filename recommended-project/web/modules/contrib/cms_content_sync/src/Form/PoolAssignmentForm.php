<?php

namespace Drupal\cms_content_sync\Form;

use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class PoolAssignmentForm extends FormBase
{
    public const STEP_SELECT_FLOW = 'flow';
    public const STEP_SELECT_POOL = 'pool';
    public const STEP_SELECT_ASSIGNMENT = 'assignment';

    protected $step;

    public function __construct()
    {
        $this->step = self::STEP_SELECT_FLOW;
    }

    public function getFormId()
    {
        return 'content_sync_pool_assignment_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $form['description'] = [
            '#type' => 'item',
            '#markup' => $this->t('This form allows you to change a pool assignment within a Flow. You can change per Pool per Flow whether the assignment should be Allow, Force or Forbid across all entity types to make changing pool assignments faster than having to go through every entity type individually via the Flow edit form.'),
        ];

        $form['wrapper'] = [
            '#type' => 'container',
            '#attributes' => [
                'id' => 'form-wrapper',
            ],
        ];

        $flow_id = $form_state->getValue('flow');
        $pool_id = $form_state->getValue('pool');
        $next_step = null;

        $flows = Flow::getAll(false);
        $options = [];
        foreach ($flows as $flow) {
            if (Flow::TYPE_BOTH === $flow->getType()) {
                continue;
            }

            $options[$flow->id] = $flow->name;
        }

        $form['wrapper']['flow'] = [
            '#type' => 'select',
            '#options' => $options,
            '#title' => $this->t('Flow'),
            '#required' => true,
            '#default_value' => $flow_id,
            '#disabled' => self::STEP_SELECT_FLOW !== $this->step,
            '#description' => $this->t('Please note that this only works for Flows that are either pulling or pushing but not both.'),
        ];

        if (self::STEP_SELECT_FLOW === $this->step) {
            $next_step = self::STEP_SELECT_POOL;
        } else {
            $pools = Pool::getAll();
            $options = [];
            foreach ($pools as $pool) {
                $options[$pool->id] = $pool->label;
            }
            $form['wrapper']['pool'] = [
                '#type' => 'select',
                '#options' => $options,
                '#title' => $this->t('Pool'),
                '#required' => true,
                '#default_value' => $pool_id,
                '#disabled' => self::STEP_SELECT_POOL !== $this->step,
            ];

            if (self::STEP_SELECT_POOL === $this->step) {
                $next_step = self::STEP_SELECT_ASSIGNMENT;
            } else {
                $flow = $flows[$flow_id];
                $type = $flow->getType();
                $pool_index = Flow::TYPE_PUSH === $type ? 'export_pools' : 'import_pools';
                $usages = [];
                foreach ($flow->sync_entities as $id => $config) {
                    if (!isset($config[$pool_index][$pool_id])) {
                        continue;
                    }

                    $usage = $config[$pool_index][$pool_id];

                    if (in_array($usage, $usages)) {
                        continue;
                    }

                    $usages[] = $usage;
                }

                $options = [
                    Pool::POOL_USAGE_ALLOW => 'allow',
                    Pool::POOL_USAGE_FORCE => 'force',
                    Pool::POOL_USAGE_FORBID => 'forbid',
                ];
                if (!count($usages)) {
                    $empty = 'Currently: Unassigned';
                } elseif (1 === count($usages)) {
                    $empty = 'Currently: '.$usages[0];
                    // No need to apply the same value.
                    unset($options[$usages[0]]);
                } else {
                    $empty = 'Currently: mixed ('.implode(', ', $usages).')';
                }

                $form['wrapper']['assignment'] = [
                    '#empty_option' => $empty,
                    '#type' => 'select',
                    '#options' => $options,
                    '#title' => $this->t('Assignment ('.$type.')'),
                    '#required' => true,
                    '#default_value' => null,
                ];
            }
        }

        if ($next_step) {
            $form['wrapper']['actions'] = [
                '#type' => 'actions',
            ];

            $form['wrapper']['actions']['continue'] = [
                '#type' => 'submit',
                '#next_step' => $next_step,
                '#value' => $this->t('Continue'),
                '#ajax' => [
                    'callback' => [$this, 'loadStep'],
                    'wrapper' => 'form-wrapper',
                    'effect' => 'fade',
                ],
            ];
        } else {
            $form['wrapper']['actions'] = [
                '#type' => 'actions',
            ];

            $form['wrapper']['actions']['submit'] = [
                '#type' => 'submit',
                '#value' => $this->t('Change assignment'),
                '#submit' => ['::submitValues'],
            ];
        }

        return $form;
    }

    public function loadStep(array &$form, FormStateInterface $form_state)
    {
        $response = new AjaxResponse();

        $response->addCommand(new HtmlCommand('#form-wrapper', $form['wrapper']));

        return $response;
    }

    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        parent::validateForm($form, $form_state);
    }

    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $triggering_element = $form_state->getTriggeringElement();
        if (isset($triggering_element['#next_step'])) {
            $this->step = $triggering_element['#next_step'];

            $form_state->setRebuild(true);
        }
    }

    public function submitValues(array &$form, FormStateInterface $form_state)
    {
        $flow_id = $form_state->getValue('flow');
        $pool_id = $form_state->getValue('pool');
        $assignment = $form_state->getValue('assignment');

        $flow = Flow::getAll(false)[$flow_id];

        $type = $flow->getType();
        $pool_index = Flow::TYPE_PUSH === $type ? 'export_pools' : 'import_pools';
        foreach ($flow->sync_entities as &$config) {
            if (!isset($config[$pool_index])) {
                continue;
            }

            $config[$pool_index][$pool_id] = $assignment;
        }

        $flow->save();

        \Drupal::messenger()->addStatus($this->t('Updated pool assignment and saved the Flow.'));
        \Drupal::messenger()->addWarning($this->t('Please export the Flow to apply the changes.'));
    }
}
