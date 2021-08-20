<?php

namespace Drupal\cms_content_sync\Controller;

use Drupal\cms_content_sync\SyncCorePoolExport;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Push changes controller.
 */
class PoolExport extends ControllerBase
{
    /**
     * Export pool.
     *
     * @param string $cms_content_sync_pool
     *                                      The id of the pool
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \EdgeBox\SyncCore\Exception\SyncCoreException
     * @throws \Exception
     *
     * @return null|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function export(string $cms_content_sync_pool, Request $request): RedirectResponse
    {
        /**
         * @var \Drupal\cms_content_sync\Entity\Pool $pool
         */
        $pool = \Drupal::entityTypeManager()
            ->getStorage('cms_content_sync_pool')
            ->load($cms_content_sync_pool);
        $messenger = \Drupal::messenger();

        if (!PoolExport::validateBaseUrl($pool)) {
            return new RedirectResponse(Url::fromRoute('entity.cms_content_sync_pool.collection')->toString());
        }

        $exporter = new SyncCorePoolExport($pool);
        $sites = $exporter->verifySiteId();

        if ('false' === $request->query->get('force', 'false') && $sites && count($sites)) {
            $url = Url::fromRoute('entity.cms_content_sync_pool.export', ['cms_content_sync_pool' => $pool->id()], ['query' => ['force' => 'true']])->toString();
            $messenger->addMessage($this->t('This site id is not unique, site with id %id is already registered with base url %base_url. If you changed the site URL and want to force the export, <a href="@url">click here</a>.', [
                '@url' => Markup::create($url),
                '%id' => array_keys($sites)[0],
                '%base_url' => array_values($sites)[0],
            ]), $messenger::TYPE_ERROR);

            return new RedirectResponse(Url::fromRoute('entity.cms_content_sync_pool.collection')->toString());
        }

        $batch = $exporter->prepareBatch(false, !empty($_GET['force']) && 'true' === $_GET['force']);
        $operations = [];
        for ($i = 0; $i < $batch->count(); ++$i) {
            $operations[] = [
                [$batch->get($i), 'execute'],
                [],
            ];
        }

        $batch = [
            'title' => t('Export configuration'),
            'operations' => $operations,
            'finished' => '\Drupal\cms_content_sync\Controller\PoolExport::batchFinished',
        ];
        batch_set($batch);

        return batch_process(Url::fromRoute('entity.cms_content_sync_pool.collection'));
    }

    /**
     * Ensure that the sites base url does not contain localhost or an IP.
     *
     * @param object $pool
     *                     The pool object
     *
     * @throws \Exception
     *
     * @return bool
     *              Returns true or false
     */
    public static function validateBaseUrl($pool): bool
    {
        $messenger = \Drupal::messenger();
        $settings = ContentSyncSettings::getInstance();
        $baseUrl = $settings->getSiteBaseUrl(true);
        if (false !== strpos($pool->backend_url, 'cms-content-sync.io')) {
            if (false !== strpos($baseUrl, 'localhost')
        || !UrlHelper::isValid($baseUrl, true)
        || '/' === mb_substr($baseUrl, -1)
        || preg_match('@https?://[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+(/|$)@', $baseUrl)) {
                $messenger->addMessage(t('The site does not have a valid base url. The base url must not contain "localhost", does not contain a trailing slash and is not allowed to be an IP address. The base url of the site can be configured <a href="@url">here.</a>', [
                    '@url' => Url::fromRoute('cms_content_sync.settings_form')->toString(),
                ]), $messenger::TYPE_ERROR);

                return false;
            }
        }

        return true;
    }

    /**
     * Batch export finished callback.
     *
     * @param $success
     * @param $results
     * @param $operations
     */
    public static function batchFinished($success, $results, $operations)
    {
        if ($success) {
            $message = t('Pool has been exported.');
        } else {
            $message = t('Pool export failed.');
        }

        \Drupal::messenger()->addMessage($message);
    }
}
