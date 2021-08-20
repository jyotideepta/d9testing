<?php

namespace Drupal\cms_content_sync;

use Drupal\cms_content_sync\Controller\ContentSyncSettings;
use Drupal\cms_content_sync\Entity\Pool;
use EdgeBox\SyncCore\Exception\NotFoundException;

class SyncCorePoolExport extends SyncCoreExport
{
    /**
     * @var \Drupal\cms_content_sync\Entity\Pool
     */
    protected $pool;

    /**
     * SyncCorePoolExport constructor.
     *
     * @param \Drupal\cms_content_sync\Entity\Pool $pool
     *                                                   The pool this exporter is used for
     */
    public function __construct(Pool $pool)
    {
        parent::__construct($pool->getClient());

        $this->pool = $pool;
    }

    /**
     * Check if another site has already connected to this pool with the same site ID.
     *
     * @return null|array
     */
    public function verifySiteId()
    {
        try {
            return $this->client->verifySiteId();
        } catch (NotFoundException $e) {
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function prepareBatch($subsequent = false, $force = false)
    {
        $base_url = ContentSyncSettings::getInstance()->getSiteBaseUrl();
        if (empty($base_url)) {
            throw new \Exception('Please provide a base_url via settings or drush command.');
        }

        $batch = $this
            ->client
            ->batch();

        // Skip creation of base entities if they are already created.
        if (!$subsequent) {
            // Register site independently of batch operations.
            $this
                ->client
                ->registerSite($force);

            // Enable previews used in the pull dashboard independently of batch operations.
            // @todo Only do this if any Flow is actually pulling manually.
            $this
                ->client
                ->getConfigurationService()
                ->enableEntityPreviews(_cms_content_sync_is_cloud_version());
        }

        $this
            ->client
            ->getConfigurationService()
            ->usePool($this->pool->id, $this->pool->label())
            ->addToBatch($batch);

        $this
            ->client
            ->getConfigurationService()
            ->usePool($this->pool->id, $this->pool->label())
            ->addToBatch($batch);

        return $batch;
    }
}
