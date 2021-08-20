<?php

namespace Drupal\cms_content_sync\SyncCoreInterface;

use Drupal\cms_content_sync\Controller\AuthenticationByUser;
use Drupal\cms_content_sync\Controller\ContentSyncSettings;
use Drupal\Core\Cache\Cache;
use EdgeBox\SyncCore\Interfaces\Embed\IEmbedService;
use EdgeBox\SyncCore\Interfaces\IApplicationInterface;

/**
 * Class DrupalApplication.
 *
 * Implement the ApplicationInterface to provide basic information about this site to the Sync Core. Must be provided
 * to all client instances to communicate with the Sync Core.
 */
class DrupalApplication implements IApplicationInterface
{
    /**
     * @var string APPLICATION_ID Unique ID to identify the kind of application
     */
    public const APPLICATION_ID = 'drupal';

    /**
     * @var \DrupalApplication
     */
    protected static $instance;

    /**
     * @return \DrupalApplication
     */
    public static function get()
    {
        if (!empty(self::$instance)) {
            return self::$instance;
        }

        return self::$instance = new DrupalApplication();
    }

    /**
     * {@inheritdoc}
     */
    public function getSiteBaseUrl()
    {
        return ContentSyncSettings::getInstance()->getSiteBaseUrl();
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthentication()
    {
        $type = ContentSyncSettings::getInstance()->getAuthenticationType();
        $authentication_provider = AuthenticationByUser::getInstance();

        return [
            'type' => $type,
            'username' => $authentication_provider->getUsername(),
            'password' => $authentication_provider->getPassword(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getRelativeReferenceForRestCall(string $flow_machine_name, string $action)
    {
        $url = '/rest/cms-content-sync/v2/'.$flow_machine_name;

        if (IApplicationInterface::REST_ACTION_LIST_ENTITIES !== $action) {
            $url .= '/[type.namespaceMachineName]/[type.machineName]';
            // When retrieving the entity we require the UUID as we're only saving
            // the UUID at status entities.
            $url .= IApplicationInterface::REST_ACTION_RETRIEVE_ENTITY === $action ? '/[entity.uuid]' : '/[entity.sharedId]';
        }

        $url .= '?_format=json';

        return $url;
    }

    /**
     * {@inheritdoc}
     */
    public function getEmbedBaseUrl(string $feature)
    {
        $export_url = $this->getSiteBaseUrl();

        $name = IEmbedService::REGISTER_SITE === $feature || IEmbedService::SITE_REGISTERED === $feature || IEmbedService::MIGRATE === $feature ? 'site' : $feature;

        $url = sprintf(
            '%s/admin/config/services/cms_content_sync/%s',
            $export_url,
            $name
        );

        // If something went wrong and the site needs to be re-registered again forcefully, we add a "force" param.
        if (IEmbedService::REGISTER_SITE === $feature) {
            $url .= '?force='.IEmbedService::REGISTER_SITE;
        } elseif (IEmbedService::MIGRATE === $feature) {
            $url .= '?force='.IEmbedService::MIGRATE;
        }

        return $url;
    }

    /**
     * {@inheritDoc}
     */
    public function setSyncCoreUrl(string $set)
    {
        ContentSyncSettings::getInstance()->setSyncCoreUrl($set);
    }

    /**
     * {@inheritDoc}
     */
    public function getSyncCoreUrl()
    {
        return ContentSyncSettings::getInstance()->getSyncCoreUrl();
    }

    /**
     * {@inheritdoc}
     */
    public function getRestUrl($pool_id, $type_machine_name, $bundle_machine_name, $version_id, $entity_uuid = null, $manually = null, $as_dependency = null)
    {
        $export_url = $this->getSiteBaseUrl();

        $url = sprintf(
            '%s/rest/cms-content-sync/%s/%s/%s/%s',
            $export_url,
            $pool_id,
            $type_machine_name,
            $bundle_machine_name,
            $version_id
        );

        if ($entity_uuid) {
            $url .= '/'.$entity_uuid;
        }

        $url .= '?_format=json';

        if ($as_dependency) {
            $url .= '&is_dependency='.$as_dependency;
        }

        if ($manually) {
            $url .= '&is_manual='.$manually;
        }

        return $url;
    }

    /**
     * {@inheritdoc}
     */
    public function getSiteName()
    {
        $name = getenv('CONTENT_SYNC_SITE_NAME');
        if ($name) {
            return $name;
        }

        try {
            return ContentSyncSettings::getInstance()->getSiteName();
        }
        // If the site is not registered yet or the Sync Core is unavailable, we return the
        // Drupal site name as default.
        catch (\Exception $e) {
            return \Drupal::config('system.site')->get('name');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setSiteId($set)
    {
        ContentSyncSettings::getInstance()->setSiteId($set);
    }

    /**
     * {@inheritdoc}
     */
    public function getSiteId()
    {
        return ContentSyncSettings::getInstance()->getSiteMachineName();
    }

    /**
     * {@inheritdoc}
     */
    public function getSiteMachineName()
    {
        return ContentSyncSettings::getInstance()->getSiteMachineName();
    }

    /**
     * {@inheritdoc}
     */
    public function setSiteMachineName($set)
    {
        ContentSyncSettings::getInstance()->setSiteMachineName($set);
    }

    /**
     * {@inheritdoc}
     */
    public function setSiteUuid(string $set)
    {
        ContentSyncSettings::getInstance()->setSiteUuid($set);

        // Clear some caches to set the local action buttons to active.
        // @ToDo: Adjust this to just clear the cache tags for the local actions.
        $bins = Cache::getBins();
        $bins['render']->deleteAll();
        $bins['discovery']->deleteAll();
    }

    /**
     * {@inheritdoc}
     */
    public function getSiteUuid()
    {
        return ContentSyncSettings::getInstance()->getSiteUuid();
    }

    /**
     * {@inheritdoc}
     */
    public function getApplicationId()
    {
        return self::APPLICATION_ID;
    }

    /**
     * {@inheritdoc}
     */
    public function getApplicationVersion()
    {
        return \Drupal::VERSION;
    }

    /**
     * {@inheritdoc}
     */
    public function getApplicationModuleVersion()
    {
        $version = \Drupal
      ::service('extension.list.module')
          ->getExtensionInfo('cms_content_sync')['version'];

        return $version ? $version : 'dev';
    }

    /**
     * {@inheritdoc}
     */
    public function getHttpClient()
    {
        return \Drupal::httpClient();
    }

    /**
     * {@inheritdoc}
     */
    public function getHttpOptions()
    {
        $options = [];

        // Allow to set a custom timeout for Sync Core requests.
        global $config;
        $config_name = 'cms_content_sync.sync_core_request_timeout';
        if (!empty($config[$config_name]) && is_int($config[$config_name])) {
            $options['timeout'] = $config[$config_name];
        }

        return $options;
    }

    public function getFeatureFlags()
    {
        return [];
    }
}
