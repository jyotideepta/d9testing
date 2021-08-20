<?php

namespace Drupal\cms_content_sync\Controller;

use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\cms_content_sync\PullIntent;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncCoreFlowExport;
use Drupal\cms_content_sync\SyncCoreInterface\SyncCoreFactory;
use Drupal\cms_content_sync\SyncCorePoolExport;
use Drupal\Core\Controller\ControllerBase;
use EdgeBox\SyncCore\V2\Raw\Model\FlowSyndicationMode;

/**
 * Migration Embed provides methods and a UI to migrate from Content Sync v1 to Content Sync v2.
 */
class Migration extends ControllerBase
{
    public const STEP_EXPORT_POOLS = 'export-pools';
    public const STEP_EXPORT_FLOWS = 'export-flows';
    public const STEP_TEST_MANUAL_PUSH = 'push-content-manually';
    public const STEP_TEST_AUTOMATED_PULL = 'pull-content-automatically';
    public const STEP_TEST_MANUAL_PULL = 'pull-content-manually';
    // TODO: Add this step to the migration as an automated step that creates one migration entity per Flow of this site. Allow users to view the progress and retry if anything fails (optimized).
    //const STEP_TEST_PUSH_ALL = 'push-all-content';
    public const STEP_SWITCH = 'switch';
    public const STEP_DONE = 'done';

    public static $pool_statuses = null;

    protected static $flow_statuses = [];

    public static function useV2($set_runtime = null)
    {
        static $value = null;
        if (null !== $set_runtime) {
            $value = $set_runtime;
            // Clear cache as the Pools with now return a different Sync Core URL.
            SyncCoreFactory::clearCache();

            foreach (Pool::getAll() as $pool) {
                $pool->getClient(true);
            }
        }
        if (null === $value) {
            $value = self::alwaysUseV2();
        }

        return $value;
    }

    public static function alwaysUseV2()
    {
        static $value = null;
        // TODO: When switching, cache statically that we're using v2. When a user
        //   creates a Flow with v2 and there is no other Flow, set the value
        //   statically as well to cover new installations. We can't set it on
        //   module install though as customers who import flow and pool config
        //   afterwards would incorrectly use v2 and we might mess up deployments.
        if (null === $value) {
            $active_step = self::getActiveStep(true);
            $value = self::STEP_DONE === $active_step;
        }

        return $value;
    }

    public static function didMigrate()
    {
        return self::alwaysUseV2() && !empty(\Drupal::service('config.factory')
            ->get('cms_content_sync.migration')
            ->get('cms_content_sync_v2_pool_statuses'));
    }

    public static function getPoolStatus($pool)
    {
        if (!self::$pool_statuses) {
            self::$pool_statuses = \Drupal::service('config.factory')
                ->get('cms_content_sync.migration')
                ->get('cms_content_sync_v2_pool_statuses');

            if (null === self::$pool_statuses) {
                self::$pool_statuses = [];
            }
        }

        if (!isset(self::$pool_statuses[$pool->id])) {
            if (!$pool->backend_url) {
                return Pool::V2_STATUS_ACTIVE;
            }

            return Pool::V2_STATUS_NONE;
        }

        return self::$pool_statuses[$pool->id];
    }

    public static function entityUsedV2(string $flow, string $type, string $bundle, ?string $uuid, ?string $shared_id, bool $pushed)
    {
        $statuses = self::getStoredFlowStatus($flow);
        foreach ($statuses['types'] as &$status) {
            if ($status['namespaceMachineName'] === $type && $status['machineName'] === $bundle) {
                $status[$pushed ? 'pushedEntity' : 'pulledEntity'] = [
                    'remoteUuid' => $uuid,
                    'remoteUniqueId' => $shared_id,
                    'verified' => false,
                ];

                break;
            }
        }

        self::setFlowStatus($flow, $statuses);
    }

    public static function getFullFlowStatus($flow)
    {
        $status = self::getFlowStatus($flow);
        if (Flow::V2_STATUS_EXPORTED === $status) {
            return self::getStoredFlowStatus($flow->id);
        }

        return [
            'exported' => Flow::V2_STATUS_ACTIVE === $status,
            'active' => Flow::V2_STATUS_ACTIVE === $status,
            'skipTest' => false,
            'skipPush' => false,
            'skipPull' => false,
            'types' => [],
        ];
    }

    public static function getFlowStatus($flow)
    {
        $status = self::getStoredFlowStatus($flow->id);

        if (empty($status)) {
            foreach ($flow->getUsedPools() as $pool) {
                if ((bool) $pool->backend_url) {
                    return Flow::V2_STATUS_NONE;
                }
            }

            return Flow::V2_STATUS_ACTIVE;
        }

        if ($status['active']) {
            return Flow::V2_STATUS_ACTIVE;
        }

        return Flow::V2_STATUS_EXPORTED;
    }

    public static function getActiveStep($avoid_recursion = false)
    {
        $pools = Pool::getAll();

        foreach ($pools as $pool) {
            if (!$pool->v2Ready()) {
                return self::STEP_EXPORT_POOLS;
            }
        }

        $flows = Flow::getAll();
        foreach ($flows as $flow) {
            if (!$flow->v2Ready()) {
                return self::STEP_EXPORT_FLOWS;
            }
        }

        if ($avoid_recursion) {
            foreach ($pools as $pool) {
                if ($pool->backend_url) {
                    return self::STEP_SWITCH;
                }
            }

            return self::STEP_DONE;
        }

        foreach ($pools as $pool) {
            if (!$pool->useV2()) {
                return self::STEP_SWITCH;
            }
        }

        return self::STEP_DONE;
    }

    public static function runPoolExport($machine_names = null)
    {
        self::useV2(true);

        foreach (Pool::getAll() as $pool) {
            if ($machine_names ? in_array($pool->id(), $machine_names) : !$pool->v2Ready()) {
                $exporter = new SyncCorePoolExport($pool);
                $batch = $exporter->prepareBatch(true);
                $batch->executeAll();

                self::setPoolV2Ready($pool);
            }
        }

        \Drupal::messenger()->addMessage(t('Successfully exported your Pools to the new Sync Core v2.'));
    }

    public static function runFlowExport($machine_names = null)
    {
        self::useV2(true);

        // TODO: Use batch operation.
        foreach (Flow::getAll() as $flow) {
            if ($machine_names ? in_array($flow->id(), $machine_names) : !$flow->v2Ready()) {
                $exporter = new SyncCoreFlowExport($flow, true);
                $batch = $exporter->prepareBatch();

                $batch->executeAll();

                self::setFlowV2Ready($flow);
            }
        }

        \Drupal::messenger()->addMessage(t('Successfully exported your Flows to the new Sync Core v2.'));
    }

    public static function skipFlowsTest($machine_names)
    {
        self::useV2(true);

        // TODO: Use batch operation.
        foreach (Flow::getAll() as $flow) {
            if (in_array($flow->id(), $machine_names)) {
                $status = self::getStoredFlowStatus($flow->id());
                $status['skipTest'] = true;
                self::setFlowStatus($flow->id(), $status);
            }
        }

        \Drupal::messenger()->addMessage(t('Successfully skipped testing your Flow(s).'));
    }

    public static function skipFlowsPush($machine_names)
    {
        self::useV2(true);

        // TODO: Use batch operation.
        foreach (Flow::getAll() as $flow) {
            if (in_array($flow->id(), $machine_names)) {
                $status = self::getStoredFlowStatus($flow->id());
                $status['skipPush'] = true;
                self::setFlowStatus($flow->id(), $status);
            }
        }

        \Drupal::messenger()->addMessage(t('Successfully skipped pushing your Flow(s).'));
    }

    public static function skipFlowsPull($machine_names)
    {
        self::useV2(true);

        // TODO: Use batch operation.
        foreach (Flow::getAll() as $flow) {
            if (in_array($flow->id(), $machine_names)) {
                $status = self::getStoredFlowStatus($flow->id());
                $status['skipPull'] = true;
                self::setFlowStatus($flow->id(), $status);
            }
        }

        \Drupal::messenger()->addMessage(t('Successfully skipped mapping your Flow(s).'));
    }

    public static function runSwitch()
    {
        self::useV2(true);

        foreach (Pool::getAll() as $pool) {
            self::setPoolV2Ready($pool, true);
        }

        \Drupal::messenger()->addMessage(t('Successfully switched to always use the new Sync Core v2. Congratulations!'));
    }

    public static function runActiveStep()
    {
        $step = self::getActiveStep();
        if (self::STEP_EXPORT_POOLS === $step) {
            self::runPoolExport();
        } elseif (self::STEP_EXPORT_FLOWS === $step) {
            self::runFlowExport();
        } elseif (self::STEP_SWITCH === $step) {
            self::runSwitch();
        } else {
            throw new \Exception("This migration step can't be automated.");
        }
    }

    public static function getSteps()
    {
        return [
            self::STEP_EXPORT_POOLS => t('Export pools'),
            self::STEP_EXPORT_FLOWS => t('Export flows'),
            // TODO:
            //self::STEP_TEST_MANUAL_PUSH => t("Push manually"),
            //self::STEP_TEST_AUTOMATED_PULL => t("Push automatically"),
            //self::STEP_TEST_MANUAL_PULL => t("Pull manually"),
            self::STEP_SWITCH => t('Switch over'),
            self::STEP_DONE => t('Done'),
        ];
    }

    public static function getStepDescriptions()
    {
        return [
            self::STEP_EXPORT_POOLS => [
                'status' => t('All pool configuration will be exported to the new Sync Core. You can still syndicate content using the old Sync Core.'),
            ],
            self::STEP_EXPORT_FLOWS => [
                'status' => t('All flow configuration will be exported to the new Sync Core. You can still syndicate content using the old Sync Core.'),
            ],
            self::STEP_TEST_MANUAL_PUSH => [
                'status' => t("Please install the 'Content Sync Health' submodule, then go to Content > Sync health > Entity status. Select one entity per Flow and use the 'Migrate to V2' action. You can still syndicate content using the old Sync Core."),
                'warning' => t('This will push the content and trigger updates on remote sites as usual.'),
            ],
            self::STEP_TEST_AUTOMATED_PULL => [
                'status' => t("Please migrate your other sites first; make sure to use the 'Migrate to V2' action on an entity that is configured with 'Pull: All' on this site. You can still syndicate content using the old Sync Core."),
                'warning' => t('This will make changes on the content you pull.'),
            ],
            self::STEP_TEST_MANUAL_PULL => [
                'status' => t("Please migrate your other sites first; make sure to use the 'Migrate to V2' action on an entity that is configured with 'Pull: Manually' on this site. You can still syndicate content using the old Sync Core."),
                'warning' => t('This will make changes on the content you pull.'),
            ],
            self::STEP_SWITCH => [
                'status' => t('Switch over'),
                'warning' => t("This action can't be undone. The old Sync Core will no longer have access to this site and all future push and pull operations to/from this site will be done by the new Sync Core."),
            ],
            self::STEP_DONE => [
                'status' => t('Congratulations! You have finished migrating your site to v2. Enjoy our new features and let us know if anything is missing!'),
            ],
        ];
    }

    public static function getAutomatedSteps()
    {
        return [
            self::STEP_EXPORT_POOLS,
            self::STEP_EXPORT_FLOWS,
            self::STEP_SWITCH,
        ];
    }

    protected static function setPoolV2Ready($pool, $switch = false)
    {
        // TODO: Change all usages of config.factory in this class to use local
        //  key value store instead.
        $settings = \Drupal::service('config.factory')
            ->getEditable('cms_content_sync.migration');

        self::$pool_statuses = $settings->get('cms_content_sync_v2_pool_statuses');
        if (!self::$pool_statuses) {
            self::$pool_statuses = [];
        }

        if ($switch) {
            self::$pool_statuses[$pool->id] = Pool::V2_STATUS_ACTIVE;
            unset($pool->backend_url);
            $pool->save();
        } else {
            self::$pool_statuses[$pool->id] = Pool::V2_STATUS_EXPORTED;
        }

        $settings
            ->set('cms_content_sync_v2_pool_statuses', self::$pool_statuses)
            ->save();
    }

    protected static function getStoredFlowStatus($id)
    {
        if (!empty(self::$flow_statuses[$id])) {
            return self::$flow_statuses[$id];
        }
        $status = \Drupal::service('config.factory')
            ->get('cms_content_sync.migration')
            ->get('flow.'.$id);
        if ($status) {
            self::$flow_statuses[$id] = $status;
        }

        return $status;
    }

    protected static function setFlowStatus($id, $status)
    {
        \Drupal::service('config.factory')
            ->getEditable('cms_content_sync.migration')
            ->set('flow.'.$id, $status)
            ->save();
        self::$flow_statuses[$id] = $status;
    }

    protected static function setFlowV2Ready($flow)
    {
        $previous = self::getStoredFlowStatus($flow->id()) ?? [];
        $all_types = $flow->getEntityTypeConfig(null, null, true);
        $types = [];
        foreach ($all_types as $config) {
            $type = $config['entity_type_name'];
            $bundle = $config['bundle_name'];
            $version = $config['version'];
            $existing = [];
            if (isset($previous['types'])) {
                foreach ($previous['types'] as $existing_type) {
                    if ($existing_type['namespaceMachineName'] === $type && $existing_type['machineName'] === $bundle) {
                        $existing = $existing_type;

                        break;
                    }
                }
            }
            $types[] = [
                'namespaceMachineName' => $type,
                'machineName' => $bundle,
                'versionId' => $version,
                'pushMode' => PushIntent::PUSH_AUTOMATICALLY === $config['export'] ? FlowSyndicationMode::ALL : (PushIntent::PUSH_MANUALLY === $config['export'] ? FlowSyndicationMode::MANUALLY : (PushIntent::PUSH_AS_DEPENDENCY === $config['export'] ? FlowSyndicationMode::DEPENDENT : null)),
                'pullMode' => PullIntent::PULL_AUTOMATICALLY === $config['import'] ? FlowSyndicationMode::ALL : (PullIntent::PULL_MANUALLY === $config['import'] ? FlowSyndicationMode::MANUALLY : (PullIntent::PULL_AS_DEPENDENCY === $config['import'] ? FlowSyndicationMode::DEPENDENT : null)),
                'pushedEntity' => isset($existing['pushedEntity']) ? $existing['pushedEntity'] : null,
                'pulledEntity' => isset($existing['pulledEntity']) ? $existing['pulledEntity'] : null,
                'skipTest' => !empty($existing['skipTest']) ? $existing['skipTest'] : false,
                'skipPush' => !empty($existing['skipPush']) ? $existing['skipPush'] : false,
                'skipPull' => !empty($existing['skipPull']) ? $existing['skipPull'] : false,
            ];
        }

        self::setFlowStatus($flow->id, [
            'exported' => true,
            'active' => false,
            'skipTest' => !empty($previous['skipTest']) ? $previous['skipTest'] : false,
            'skipPush' => !empty($previous['skipPush']) ? $previous['skipPush'] : false,
            'skipPull' => !empty($previous['skipPull']) ? $previous['skipPull'] : false,
            'types' => $types,
        ]);
    }
}
