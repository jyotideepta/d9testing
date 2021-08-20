<?php

namespace Drupal\cms_content_sync\Form;

use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides a node deletion confirmation form.
 *
 * @internal
 */
class PushChangesConfirm extends ConfirmFormBase
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
     * Constructs a DeleteMultiple form object.
     *
     * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
     *                                                                           The tempstore factory
     * @param \Drupal\Core\Entity\EntityTypeManager          $manager
     *                                                                           The entity manager
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    public function __construct(PrivateTempStoreFactory $temp_store_factory, EntityTypeManager $manager)
    {
        $this->tempStoreFactory = $temp_store_factory;
        $this->storage = $manager->getStorage('node');
        $this->nodes = $this->tempStoreFactory->get('node_cms_content_sync_push_changes_confirm')->get('nodes');
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
        return 'node_cms_content_sync_push_changes_confirm';
    }

    /**
     * {@inheritdoc}
     */
    public function getQuestion()
    {
        return 'Are you sure you want to push this content? Depending on the amount and complexity of it, this action may take a while.';
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
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        if (empty($this->nodes)) {
            return new RedirectResponse($this->getCancelUrl()->setAbsolute()->toString());
        }

        $items = [];
        foreach ($this->nodes as $node) {
            $items[$node->id()] = $node->label();
        }

        $form['nodes'] = [
            '#theme' => 'item_list',
            '#items' => $items,
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        if ($form_state->getValue('confirm')) {
            $ignored = 0;

            /** @var \Drupal\node\NodeInterface[] $nodes */
            foreach ($this->nodes as $node) {
                if (!PushIntent::pushEntityFromUi(
                    $node,
                    PushIntent::PUSH_MANUALLY,
                    SyncIntent::ACTION_UPDATE
                )) {
                    ++$ignored;
                }
            }

            // @todo Improve "ignore" messages (see individual "Push" operation)
            \Drupal::messenger()->addMessage(t('Pushed @count content items.', ['@count' => count($this->nodes) - $ignored]));
            if ($ignored) {
                \Drupal::messenger()->addWarning(t('@count content items have been ignored as they\'re not configured to be pushed.', ['@count' => $ignored]));
            }
            $this->logger('cms_content_sync')->notice('Pushed @count content, ignored @ignored.', ['@count' => count($this->nodes) - $ignored, '@ignored' => $ignored]);
            $this->tempStoreFactory->get('node_cms_content_sync_push_changes_confirm')->delete('nodes');
        }
        $form_state->setRedirect('system.admin_content');
    }
}
