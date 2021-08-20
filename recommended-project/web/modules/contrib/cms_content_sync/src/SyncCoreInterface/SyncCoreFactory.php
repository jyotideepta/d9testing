<?php

namespace Drupal\cms_content_sync\SyncCoreInterface;

use Drupal\cms_content_sync\Entity\Pool;
use EdgeBox\SyncCore\V1\SyncCore;

/**
 * Class SyncCoreFactory.
 *
 * This is the central interface between Drupal and the Sync Core. Drupal will call ::getSyncCore() and then use the
 * Sync Core library whereas the Sync Core library in return uses the interface functions provided here.
 */
class SyncCoreFactory
{
    /**
     * @var \EdgeBox\SyncCore\Interfaces\ISyncCore[]
     */
    protected static $clients = [];

    protected static $v2 = null;

    /**
     * @var \EdgeBox\SyncCore\Interfaces\ISyncCore[]
     */
    protected static $allSyncCores = null;

    public static function getSyncCoreV2Url()
    {
        $url = getenv('CONTENT_SYNC_SYNC_CORE_URL');
        if (!$url) {
            $url = DrupalApplication::get()->getSyncCoreUrl();
            if (!$url) {
                throw new \Exception('No Sync Core URL set. Please register this site first.');
            }
        }

        return $url;
    }

    /**
     * Allow missing base URL.
     *
     * @return \EdgeBox\SyncCore\V2\SyncCore
     */
    public static function getDummySyncCoreV2()
    {
        try {
            return self::getSyncCoreV2();
        } catch (\Exception $e) {
        }

        return new \EdgeBox\SyncCore\V2\SyncCore(DrupalApplication::get(), 'https://example.com/sync-core');
    }

    /**
     * @param mixed $based_on_current_setting
     *
     * @return \EdgeBox\SyncCore\V2\SyncCore
     */
    public static function getSyncCoreV2($based_on_current_setting = false)
    {
        if ($based_on_current_setting) {
            $url = DrupalApplication::get()->getSyncCoreUrl();
            if ($url) {
                return new \EdgeBox\SyncCore\V2\SyncCore(DrupalApplication::get(), $url);
            }
        }

        if (!empty(self::$v2)) {
            return self::$v2;
        }

        return self::$v2 = new \EdgeBox\SyncCore\V2\SyncCore(DrupalApplication::get(), self::getSyncCoreV2Url());
    }

    public static function getAllV1SyncCores()
    {
        $all = self::getAllSyncCores();
        $v1 = [];
        foreach ($all as $url => $core) {
            if ($core instanceof SyncCore) {
                $v1[$url] = $core;
            }
        }

        return $v1;
    }

    /**
     * Return an instance of the ISyncCore Client for the given URL.
     * Cache clients.
     *
     * @param string $sync_core_url
     *                              The base URL to the Sync Core
     *
     * @return \EdgeBox\SyncCore\Interfaces\ISyncCore
     */
    public static function getSyncCore($sync_core_url)
    {
        if (!empty(self::$clients[$sync_core_url])) {
            return self::$clients[$sync_core_url];
        }

        return self::$clients[$sync_core_url] = new SyncCore(DrupalApplication::get(), $sync_core_url);
    }

    /**
     * @return \EdgeBox\SyncCore\Interfaces\ISyncCore[]
     */
    public static function getAllSyncCores()
    {
        if (!empty(self::$allSyncCores)) {
            return self::$allSyncCores;
        }

        $cores = [];

        foreach (Pool::getAll() as $pool) {
            $url = parse_url($pool->getSyncCoreUrl());
            $host = $url['host'];
            if (isset($cores[$host])) {
                continue;
            }

            if ($pool->useV2()) {
                $cores[$host] = self::getSyncCoreV2();
            } else {
                $cores[$host] = self::getSyncCore($pool->getSyncCoreUrl());
            }
        }

        return self::$allSyncCores = $cores;
    }

    public static function clearCache()
    {
        self::$allSyncCores = null;
        self::$clients = [];
    }

    /**
     * @return null|\EdgeBox\SyncCore\Interfaces\ISyncCore
     */
    public static function getAnySyncCore()
    {
        $cores = self::getAllSyncCores();
        if (!count($cores)) {
            return null;
        }

        return reset($cores);
    }
}
