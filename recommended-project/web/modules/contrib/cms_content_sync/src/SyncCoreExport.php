<?php

namespace Drupal\cms_content_sync;

abstract class SyncCoreExport
{
    /**
     * @var \EdgeBox\SyncCore\Interfaces\ISyncCore
     */
    protected $client;

    /**
     * @param \EdgeBox\SyncCore\Interfaces\ISyncCore $client
     */
    public function __construct($client)
    {
        $this->client = $client;
    }

    /**
     * @return \EdgeBox\SyncCore\Interfaces\ISyncCore
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Prepare the Sync Core push as a batch operation. Return a batch array
     * with single steps to be executed.
     *
     * @throws \EdgeBox\SyncCore\Exception\SyncCoreException
     *
     * @return \EdgeBox\SyncCore\Interfaces\IBatch
     */
    abstract public function prepareBatch();
}
