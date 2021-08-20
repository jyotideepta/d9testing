<?php

namespace Drupal\cms_content_sync\Controller;

use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\cms_content_sync\Form\MigrationForm;
use Drupal\cms_content_sync\Plugin\Type\EntityHandlerPluginManager;
use Drupal\cms_content_sync\SyncCoreInterface\DrupalApplication;
use Drupal\cms_content_sync\SyncCoreInterface\SyncCoreFactory;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use EdgeBox\SyncCore\Exception\UnauthorizedException;
use EdgeBox\SyncCore\Interfaces\Embed\IEmbedService;
use Firebase\JWT\JWT;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class Embed provides helpers to embed Sync Core functionality into the site.
 */
class Embed extends ControllerBase
{
    protected $core;
    protected $settings;
    protected $params;
    protected $embedService;

    public function syndicationDashboard()
    {
        $this->init();

        $embed = $this->embedService->syndicationDashboard($this->params + [
            'selectedFlowMachineName' => \Drupal::request()->request->get('flow'),
        ]);

        return $this->run($embed, DrupalApplication::get()->getSiteUuid() ? ['form' => \Drupal::formBuilder()->getForm(MigrationForm::class)] : []);
    }

    public function migrate()
    {
        $this->init();

        $all_pools = Pool::getAll();
        $pools = [];
        foreach ($all_pools as $id => $pool) {
            $pools[] = [
                'machineName' => $pool->id(),
                'name' => $pool->label(),
                'exported' => $pool->v2Ready(),
            ];
        }

        $all_flows = Flow::getAll();
        $flows = [];
        $pushes = false;
        foreach ($all_flows as $id => $flow) {
            if (Flow::TYPE_PULL !== $flow->getType()) {
                $pushes = true;
            }
            $flows[] = [
                'machineName' => $flow->id(),
                'name' => $flow->label(),
                'exported' => $flow->v2Ready(),
            ] + Migration::getFullFlowStatus($flow);
        }

        $health_module_enabled = \Drupal::moduleHandler()->moduleExists('cms_content_sync_health');

        $embed = $this->embedService->migrate($this->params + [
            'pools' => $pools,
            'flows' => $flows,
            'migrated' => false,
            'settings' => [
                'mustEnableHealthSubmodule' => $pushes && !$health_module_enabled,
                'pushToV2Url' => Url::fromRoute('view.content_sync_entity_status.entity_status_overview', [], ['query' => ['v2' => 'yes'], 'absolute' => true])->toString(),
                'pullManuallyFromV2Url' => $health_module_enabled ? Url::fromRoute('entity.cms_content_sync.content', [], ['query' => ['v2' => 'yes'], 'absolute' => true])->toString() : null,
                'switched' => Migration::alwaysUseV2(),
            ],
        ]);

        return $this->run($embed, DrupalApplication::get()->getSiteUuid() ? ['form' => \Drupal::formBuilder()->getForm(MigrationForm::class)] : []);
    }

    public function site()
    {
        $this->init(true);

        // Already registered if this exists.
        $uuid = $this->settings->getSiteUuid();

        $force = empty($this->params['force']) ? null : $this->params['force'];

        if (IEmbedService::MIGRATE === $force || (!Migration::useV2() && empty($force))) {
            return $this->migrate();
        }

        $this->params['migrated'] = Migration::didMigrate();

        $embed = $uuid && (empty($this->params['force']) || IEmbedService::REGISTER_SITE !== $this->params['force'])
        ? $this->embedService->siteRegistered($this->params)
        : $this->embedService->registerSite($this->params);

        if ($uuid && empty($this->params)) {
            $step = Migration::getActiveStep();
            if (Migration::STEP_DONE !== $step) {
                $link = \Drupal::l(t('migrate now'), \Drupal\Core\Url::fromRoute('cms_content_sync_flow.migration'));
                \Drupal::messenger()->addMessage(t('You have Sync Cores from our old v1 release. Support for this Sync Core will end on December 31st, 2021. Please @link.', ['@link' => $link]), 'warning');
            }
        }

        try {
            return $this->run($embed);
        }
        // The site registration JWT expires after only 5 minutes, then the user must get a new one.
        catch (UnauthorizedException $e) {
            \Drupal::messenger()->addMessage('Your registration token expired. Please try again.', 'warning');

            $url = \Drupal::request()->getRequestUri();
            // Remove invalid query params so the user can try again.
            $url = explode('?', $url)[0];

            return RedirectResponse::create($url);
        }
    }

    public function pullDashboard()
    {
        $this->init();

        $embed = $this->embedService->pullDashboard([]);

        return $this->run($embed);
    }

    public function nodeStatus(NodeInterface $node)
    {
        $this->init();

        $user = \Drupal::currentUser();

        $params = [
            'configurationAccess' => $user->hasPermission('administer cms content sync'),
        ];

        if (EntityHandlerPluginManager::isEntityTypeConfiguration($node->getEntityTypeId())) {
            $params['remoteUniqueId'] = $node->id();
        } else {
            $params['remoteUuid'] = $node->uuid();
        }

        // TODO: Show warning if not using Sync Core v2 yet, so users understand why it's empty.

        $embed = $this->embedService->entityStatus($params);

        return $this->run($embed);
    }

    protected function init($expectJwtWithSyncCoreUrl = false)
    {
        $this->params = \Drupal::request()->query->all();

        if ($expectJwtWithSyncCoreUrl) {
            if (isset($this->params['uuid'], $this->params['jwt'])) {
                $tks = explode('.', $this->params['jwt']);
                list(, $bodyb64) = $tks;
                $payload = JWT::jsonDecode(JWT::urlsafeB64Decode($bodyb64));
                if (!empty($payload->syncCoreBaseUrl)) {
                    DrupalApplication::get()->setSyncCoreUrl($payload->syncCoreBaseUrl);
                }
            }
        }

        $this->core = SyncCoreFactory::getDummySyncCoreV2();

        $this->settings = ContentSyncSettings::getInstance();

        $this->embedService = $this->core->getEmbedService();
    }

    protected function run($embed, $extras = [])
    {
        $result = $embed->run();

        $redirect = $result->getRedirectUrl();

        if ($redirect) {
            return RedirectResponse::create($redirect);
        }

        $html = $result->getRenderedHtml();

        // TODO: Put this in a theme file.
        return [
            '#type' => 'inline_template',
            '#template' => '<div className="content-sync-embed-wrapper" style="margin-top: 20px;">{{ html|raw }}</div>',
            '#context' => [
                'html' => $html,
            ],
        ] + $extras;
    }
}
