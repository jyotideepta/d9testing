<?php

namespace Drupal\cms_content_sync\Controller;

use Drupal\cms_content_sync\Entity\Pool;
use Drupal\cms_content_sync\SyncCoreInterface\SyncCoreFactory;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use EdgeBox\SyncCore\Exception\NotFoundException;
use EdgeBox\SyncCore\Interfaces\IApplicationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class ContentSyncSettings.
 *
 * Helper functions to set and get the settings of this page.
 */
class ContentSyncSettings
{
    /**
     * @var \Drupal\Core\Config\ConfigFactoryInterface
     */
    protected $configFactory;

    /**
     * @var string[]
     */
    protected $embedEntities;

    /**
     * @var bool
     */
    protected $isPreviewEnabled;

    /**
     * @var string
     */
    protected $authenticationType;

    /**
     * @var string
     */
    protected $siteBaseUrl;

    /**
     * @var string
     */
    protected $syncCoreUrl;

    /**
     * @var null|string
     */
    protected $siteMachineName;

    /**
     * @var null|string
     */
    protected $siteId;

    /**
     * @var null|string
     */
    protected $siteUuid;

    /**
     * @var null|string
     */
    protected $siteName;

    /**
     * Constructs a new EntityListBuilder object.
     */
    public function __construct(ConfigFactoryInterface $configFactory)
    {
        $this->configFactory = $configFactory;
    }

    /**
     * Singleton.
     *
     * @return ContentSyncSettings
     */
    public static function getInstance()
    {
        static $instance = null;
        if ($instance) {
            return $instance;
        }

        return $instance = self::createInstance(\Drupal::getContainer());
    }

    /**
     * Create a new instance of the controller with the services of the given container.
     *
     * @return ContentSyncSettings
     */
    public static function createInstance(ContainerInterface $container)
    {
        return new static(
      $container->get('config.factory')
    );
    }

    /**
     * @return string[]
     */
    public function getEmbedEntities()
    {
        if (null !== $this->embedEntities) {
            return $this->embedEntities;
        }

        $value = $this
            ->configFactory
            ->get('cms_content_sync.settings')
            ->get('cms_content_sync_embed_entities');

        if (!$value) {
            $value = [];
        }

        return $this->embedEntities = $value;
    }

    /**
     * @param string[] $set
     */
    public function setEmbedEntities($set)
    {
        $this
            ->configFactory
            ->getEditable('cms_content_sync.settings')
            ->set('cms_content_sync_embed_entities', $set)
            ->save();

        $this->embedEntities = $set;
    }

    /**
     * @return bool
     */
    public function isPreviewEnabled()
    {
        if (null !== $this->isPreviewEnabled) {
            return $this->isPreviewEnabled;
        }

        return $this->isPreviewEnabled = boolval(
            $this
                ->configFactory
                ->get('cms_content_sync.settings')
                ->get('cms_content_sync_enable_preview')
        );
    }

    /**
     * @param bool $set
     */
    public function setPreviewEnabled($set)
    {
        $this
            ->configFactory
            ->getEditable('cms_content_sync.settings')
            ->set('cms_content_sync_enable_preview', $set)
            ->save();

        $this->isPreviewEnabled = $set;
    }

    /**
     * @return string
     */
    public function getAuthenticationType()
    {
        if (null !== $this->authenticationType) {
            return $this->authenticationType;
        }

        $this->authenticationType = $this
            ->configFactory
            ->get('cms_content_sync.settings')
            ->get('cms_content_sync_authentication_type');

        if (!$this->authenticationType) {
            if (\Drupal::service('module_handler')->moduleExists('basic_auth')) {
                $this->authenticationType = IApplicationInterface::AUTHENTICATION_TYPE_BASIC_AUTH;
            } else {
                $this->authenticationType = IApplicationInterface::AUTHENTICATION_TYPE_COOKIE;
            }
        }

        return $this->authenticationType;
    }

    /**
     * @param string $set
     */
    public function setAuthenticationType($set)
    {
        $this
            ->configFactory
            ->getEditable('cms_content_sync.settings')
            ->set('cms_content_sync_authentication_type', $set)
            ->save();

        $this->authenticationType = $set;
    }

    /**
     * @param bool $config_only
     *                          Whether to return the base URL as per the settings only. Otherwise it will return the
     *                          default site URL.
     *
     * @throws \Exception
     *
     * @return string
     */
    public function getSiteBaseUrl($config_only = false)
    {
        if (null !== $this->siteBaseUrl) {
            return $this->siteBaseUrl;
        }

        // Allow overwritting of the base_url from the global $settings.
        $setting = Settings::get('cms_content_sync_base_url', \Drupal::state()->get('cms_content_sync.base_url'));

        if ($config_only) {
            return $setting;
        }

        if ($setting) {
            // Validate the Base URL.
            if (UrlHelper::isValid($setting, true) && '/' !== mb_substr($setting, -1)) {
                return $this->siteBaseUrl = $setting;
            }

            throw new \Exception(t('The configured base URL is not a valid URL. Ensure that it does not contain a trailing slash.'));
        }

        global $base_url;

        return $this->siteBaseUrl = $base_url;
    }

    /**
     * @param string $set
     */
    public function setSiteBaseUrl($set)
    {
        \Drupal::state()->set('cms_content_sync.base_url', $set);

        // Reset so it's computed again.
        $this->siteBaseUrl = null;
    }

    /**
     * @return null|string
     */
    public function getSyncCoreUrl()
    {
        if (null !== $this->syncCoreUrl) {
            return $this->syncCoreUrl;
        }

        return $this->syncCoreUrl = $this
            ->configFactory
            ->get('cms_content_sync.settings')
            ->get('cms_content_sync_sync_core_url');
    }

    /**
     * @param string $set
     */
    public function setSyncCoreUrl($set)
    {
        $this
            ->configFactory
            ->getEditable('cms_content_sync.settings')
            ->set('cms_content_sync_sync_core_url', $set)
            ->save();

        // Reset so it's computed again.
        $this->syncCoreUrl = null;
    }

    /**
     * Whether or not users can directly communicate with the Sync Core. This only makes sense if the Sync Core is
     * exposed publicly so right it's restricted to the Cloud version of Content Sync.
     * The setting is saved in the Sync Core, not locally at this site. Otherwise the configuration of multiple sites
     * would conflict and lead to undesired outcomes.
     *
     * @param bool $default
     *                      What to assume as the default value if no explicit settings are saved yet
     *
     * @throws \EdgeBox\SyncCore\Exception\SyncCoreException
     *
     * @return bool
     */
    public function isDirectSyncCoreAccessEnabled($default = true)
    {
        static $cache = null;

        if (null !== $cache) {
            return $cache;
        }

        $checked = [];

        // We don't distinguish between multiple Sync Cores. If it's enabled for one, it's enabled for all.
        foreach (Pool::getAll() as $pool) {
            $url = $pool->getSyncCoreUrl();
            if (in_array($url, $checked)) {
                continue;
            }
            $checked[] = $url;

            try {
                $setting = $pool
                    ->getClient()
                    ->isDirectUserAccessEnabled();

                if ($setting) {
                    return $cache = true;
                }
                if (false === $setting && null === $cache) {
                    $cache = false;
                }
            } catch (NotFoundException $e) {
                // No config exported yet, so skip this.
                continue;
            }
        }

        // If it's not set at all, we default it to TRUE so people benefit from the increased performance.
        if (null === $cache) {
            return $cache = $default;
        }

        return $cache = false;
    }

    /**
     * Set whether or not to allow direct Sync Core communication.
     *
     * @param bool $set
     *
     * @throws \Exception
     */
    public function setDirectSyncCoreAccessEnabled($set)
    {
        $checked = [];

        // We don't distinguish between multiple Sync Cores. If it's enabled for one, it's enabled for all.
        foreach (Pool::getAll() as $pool) {
            $url = $pool->getSyncCoreUrl();
            if (in_array($url, $checked)) {
                continue;
            }
            $checked[] = $url;

            try {
                $pool
                    ->getClient()
                    ->isDirectUserAccessEnabled($set);
            } catch (NotFoundException $e) {
                // No config exported yet, so skip this.
                continue;
            }
        }
    }

    /**
     * @return null|string
     */
    public function getSiteMachineName()
    {
        if (null !== $this->siteMachineName) {
            return $this->siteMachineName;
        }

        return $this->siteMachineName = \Drupal::state()
            ->get('cms_content_sync.site_machine_name');
    }

    /**
     * @param string $set
     */
    public function setSiteMachineName($set)
    {
        \Drupal::state()->set('cms_content_sync.site_machine_name', $set);

        // Reset so it's computed again.
        $this->siteMachineName = null;
    }

    /**
     * @throws \Exception
     *
     * @return null|string
     */
    public function getSiteId()
    {
        if (null !== $this->siteId) {
            return $this->siteId;
        }

        // Allow overwritting of the site_id from the global $settings.
        return $this->siteId = Settings::get('cms_content_sync_site_id', \Drupal::state()->get('cms_content_sync.site_id'));
    }

    /**
     * @param string $set
     */
    public function setSiteId($set)
    {
        \Drupal::state()->set('cms_content_sync.site_id', $set);

        // Reset so it's computed again.
        $this->siteId = null;
    }

    /**
     * @throws \Exception
     *
     * @return null|string
     */
    public function getSiteUuid()
    {
        if (null !== $this->siteUuid) {
            return $this->siteUuid;
        }

        // Allow overwritting of the site_uuid from the global $settings.
        return $this->siteUuid = Settings::get('cms_content_sync_site_uuid', \Drupal::state()->get('cms_content_sync.site_uuid'));
    }

    /**
     * @param string $set
     */
    public function setSiteUuid($set)
    {
        \Drupal::state()->set('cms_content_sync.site_uuid', $set);

        // Reset so it's computed again.
        $this->siteUuid = null;
    }

    /**
     * Remove the site ID setting from the current site's state, requesting a
     * new one during the next config export.
     */
    public function resetSiteId()
    {
        // Reset site ID.
        \Drupal::state()->delete('cms_content_sync.site_id');
        // Reset so it's computed again.
        $this->siteId = null;

        // Reset machine name.
        \Drupal::state()->delete('cms_content_sync.site_machine_name');
        // Reset so it's computed again.
        $this->siteMachineName = null;
    }

    /**
     * @param bool $config_only
     *
     * @return null|string
     */
    public function getSiteName()
    {
        if (null !== $this->siteName) {
            return $this->siteName;
        }

        // Allow overwritting of the site_name from the global $settings.
        if (Settings::get('cms_content_sync_site_name')) {
            return $this->siteName = Settings::get('cms_content_sync_site_name');
        }

        // If we aren't connected yet, don't ask the Sync Core for our name (will
        // throw an exception).
        if ($this->getSiteId()) {
            $core = SyncCoreFactory::getAnySyncCore();
            if ($core) {
                $stored = $core->getSiteName();

                if ($stored) {
                    return $this->siteName = $stored;
                }
            }
        }

        $this->siteName = $this
            ->configFactory
            ->get('system.site')
            ->get('name');

        return $this->siteName;
    }

    /**
     * @param string $set
     */
    public function setSiteName($set)
    {
        foreach (SyncCoreFactory::getAllSyncCores() as $core) {
            $core->setSiteName($set);
        }
    }

    /**
     * Get the Extended Entity Export Logging setting.
     */
    public function getExtendedEntityExportLogging()
    {
        return \Drupal::service('keyvalue.database')->get('cms_content_sync_debug')->get('extended_entity_export_logging');
    }

    /**
     * Set the Extended Entity Export Logging setting.
     *
     * @param mixed $value
     */
    public function setExtendedEntityExportLogging($value)
    {
        $key_value_db = \Drupal::service('keyvalue.database');
        $key_value_db->get('cms_content_sync_debug')->set('extended_entity_export_logging', $value);
    }

    /**
     * Get the Extended Entity Import Logging setting.
     */
    public function getExtendedEntityImportLogging()
    {
        return \Drupal::service('keyvalue.database')->get('cms_content_sync_debug')->get('extended_entity_import_logging');
    }

    /**
     * Set the Extended Entity Import Logging setting.
     *
     * @param mixed $value
     */
    public function setExtendedEntityImportLogging($value)
    {
        $key_value_db = \Drupal::service('keyvalue.database');

        $key_value_db->get('cms_content_sync_debug')->set('extended_entity_import_logging', $value);
    }
}
