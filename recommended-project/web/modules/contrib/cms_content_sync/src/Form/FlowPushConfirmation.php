<?php

namespace Drupal\cms_content_sync\Form;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a pool pull all confirmation page.
 *
 * @internal
 */
class FlowPushConfirmation extends ConfirmFormBase
{
    /**
     * The tempstore factory.
     *
     * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
     */
    protected $tempStoreFactory;

    /**
     * The node storage.
     *
     * @var \Drupal\Core\Entity\EntityStorageInterface
     */
    protected $storage;

    /**
     * The nodes to push.
     *
     * @var array
     */
    protected $nodes;

    /**
     * The flow configuration.
     */
    protected $flow;

    /**
     * @var \Drupal\Core\Entity\EntityStorageInterface
     *
     * The flow storage
     */
    protected $flow_storage;

    /**
     * The content sync flow machine name.
     *
     * @var string
     */
    protected $cms_content_sync_flow;

    /**
     * Constructs a DeleteMultiple form object.
     *
     * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
     *                                                                           The tempstore factory
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    public function __construct(PrivateTempStoreFactory $temp_store_factory, EntityTypeManager $manager)
    {
        $this->tempStoreFactory = $temp_store_factory;
        $this->flow_storage = $manager->getStorage('cms_content_sync_flow');
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state, $cms_content_sync_flow = null)
    {
        $this->cms_content_sync_flow = $cms_content_sync_flow;
        $this->flow = $this->flow_storage->load($this->cms_content_sync_flow);

        // If the flow is migrated to v2, the syndication dashboard will be used.
        if ($this->flow->useV2()) {
            return $this->redirect('cms_content_sync.syndication', ['flow' => $this->flow->id(), 'new' => 'true']);
        }

        $form['push_mode'] = [
            '#type' => 'radios',
            '#options' => [
                'automatic' => $this->t('Push entities that match "Push: All".'),
                'automatic_manual' => $this->t('Push entities that match "Push: All" and entities that match "Push: Manually" and have been pushed before.'),
                'automatic_manual_force' => $this->t('Push entities that match "Push: All" and entities that match "Push: Manually" even if they were never pushed before.'),
            ],
            '#default_value' => 'automatic',
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
      $container->get('tempstore.private'),
      $container->get('entity_type.manager')
    );
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'pool_pull_confirmation';
    }

    /**
     * {@inheritdoc}
     */
    public function getQuestion()
    {
        return 'Do you really want to push all entities for this flow?';
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription()
    {
        return $this->t('Depending on the amount of entities this could take a while.');
    }

    /**
     * {@inheritdoc}
     */
    public function getCancelUrl()
    {
        return new Url('system.admin_content');
    }

    /**
     * {@inheritdoc}
     */
    public function getConfirmText()
    {
        return t('Push');
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $form_state->setRedirect('entity.cms_content_sync_flow.push', ['cms_content_sync_flow' => $this->flow->id(), 'push_mode' => $form_state->getValue('push_mode')]);
    }
}
