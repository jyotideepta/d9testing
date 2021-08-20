<?php

namespace Drupal\cms_content_sync\Cli;

use Drupal\cms_content_sync\Controller\FlowPull;
use Drupal\cms_content_sync\Controller\PoolExport;
use Drupal\cms_content_sync\Entity\EntityStatus;
use Drupal\cms_content_sync\Entity\Flow;
use Drupal\cms_content_sync\Entity\Pool;
use Drupal\cms_content_sync\PushIntent;
use Drupal\cms_content_sync\SyncCoreFlowExport;
use Drupal\cms_content_sync\SyncCoreInterface\SyncCoreFactory;
use Drupal\cms_content_sync\SyncCorePoolExport;
use Drupal\cms_content_sync\SyncIntent;
use Drupal\Component\Uuid\Uuid;
use Drush\Exceptions\UserAbortException;
use EdgeBox\SyncCore\Exception\TimeoutException;

class CliService
{
    /**
     * Export the configuration to the Sync Core.
     *
     * @param ICLIIO $io
     *                        The CLI service which allows interoperability
     * @param array  $options
     *                        An array containing the option parameters provided by Drush
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \EdgeBox\SyncCore\Exception\SyncCoreException
     * @throws \Exception
     */
    public function configuration_export($io, $options)
    {
        $io->text('Validating Pools...');
        foreach (Pool::getAll() as $pool) {
            if (!PoolExport::validateBaseUrl($pool)) {
                throw new \Exception('The site does not have a valid base url. The base url must not contain "localhost" and is not allowed to be an IP address. The base url of the site can be configured at the CMS Content Sync settings page.');
            }

            $exporter = new SyncCorePoolExport($pool);
            $sites = $exporter->verifySiteId();

            if (!$options['force'] && $sites && count($sites)) {
                throw new \Exception('Another site with id '.array_keys($sites)[0].' and base url '.array_values($sites)[0].' already exists for the pool "'.$pool->id.'"');
            }
        }
        $io->text('Finished validating Pools.');

        $io->text('Starting Flow export...');
        $count = 0;
        foreach (Flow::getAll() as $flow) {
            $io->text('> Exporting Flow '.$flow->label().'...');
            $exporter = new SyncCoreFlowExport($flow);
            $batch = $exporter->prepareBatch($options['force']);
            $io->text('>> Executing '.$batch->count().' operations...');
            $batch->executeAll();
            ++$count;
        }
        $io->text('Finished export of '.$count.' Flow(s).');

        $io->text('Deleting old configuration...');
        SyncCoreFlowExport::deleteUnusedFlows();

        $io->success('Export completed.');
    }

    /**
     * Kindly ask the Sync Core to login again.
     *
     * @param ICLIIO $io
     *                   The CLI service which allows interoperability
     *
     * @throws \EdgeBox\SyncCore\Exception\SyncCoreException
     */
    public function sync_core_login($io)
    {
        $io->text('Asking all connected Sync Cores to refresh the login to this site...');
        $io->text('Please note that this only works for old v1 Sync Cores.');

        $cores = SyncCoreFactory::getAllSyncCores();
        foreach ($cores as $host => $core) {
            if ($core->getSyndicationService()->refreshAuthentication()) {
                $io->text('SUCCESS login from Sync Core at '.$host);
            } else {
                $io->error('FAILED to login from Sync Core at '.$host);
            }
        }

        $io->success('Done.');
    }

    /**
     * Kindly ask the Sync Core to pull all entities for a specific flow, or to
     * force pull one specific entity.
     *
     * @param ICLIIO $io
     *                        The CLI service which allows interoperability
     * @param string $flow_id
     *                        The flow the entities should be pulled from
     * @param array  $options
     *                        An array containing the option parameters provided by Drush
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \EdgeBox\SyncCore\Exception\SyncCoreException
     */
    public function pull($io, $flow_id, $options)
    {
        $force = $options['force'];

        $entity_type = $options['entity_type'];
        $entity_uuid = $options['entity_uuid'];

        if ('' != $entity_uuid && null == $entity_type) {
            $io->error('If a specific entity_uuid should be pulled, the entity_type also has to be set.');

            return;
        }

        // @todo Allow pulling of all entities of a specific type for one specific flow.
        if ('' != $entity_type && null == $entity_uuid) {
            $io->error('If the entity_type option is set, the entity_uuid to be pulled also has to be specified.');

            return;
        }

        if (!is_null($entity_uuid) && !is_null($entity_uuid)) {
            if (UUID::isValid($entity_uuid)) {
                // Pull a single entity.
                // @todo Allow pull for single entities which have not been pulled before.
                FlowPull::force_pull_entity($flow_id, $entity_type, $entity_uuid);
            } else {
                $io->error('The specified entity_uuid is invalid.');
            }
        } else {
            // Pull all entities for the specified flow.
            $flows = Flow::getAll();

            foreach ($flows as $id => $flow) {
                if ($flow_id && $id != $flow_id) {
                    continue;
                }

                $result = FlowPull::pullAll($flow, $force);

                if (empty($result)) {
                    $io->text('No automated pull configured for Flow: '.$flow->label());

                    continue;
                }

                $io->text('Started pulling for Flow: '.$flow->label());

                foreach ($result as $operation) {
                    $operation->execute();

                    if (!($goal = $operation->total())) {
                        $io->text('> Nothing to do for: '.$operation->getTypeMachineName().'.'.$operation->getBundleMachineName().' from '.$operation->getSourceName());

                        continue;
                    }

                    $progress = 0;

                    while ($progress < $goal) {
                        if ($progress > 0) {
                            sleep(5);
                        }

                        try {
                            $progress = $operation->progress();
                        } catch (TimeoutException $e) {
                            $io->text('> Timeout when asking the Sync Core to report on the progress of pulling '.$goal.' '.$operation->getTypeMachineName().'.'.$operation->getBundleMachineName().' from '.$operation->getSourceName().'. Will try again in 15 seconds...');
                            sleep(15);

                            continue;
                        }

                        if ($progress == $goal) {
                            $io->text('> Finished '.$goal.' operations for '.$operation->getTypeMachineName().'.'.$operation->getBundleMachineName().' from '.$operation->getSourceName());
                        } elseif (0 == $progress) {
                            sleep(5);
                        } else {
                            $io->text('> Finished '.$progress.' of '.$goal.' operations for '.$operation->getTypeMachineName().'.'.$operation->getBundleMachineName().' from '.$operation->getSourceName().': '.floor($progress / $goal * 100).'%');
                        }
                    }
                }
            }
        }
    }

    /**
     * Kindly ask the Sync Core to pull all entities for a specific flow.
     *
     * @param ICLIIO $io
     *                        The CLI service which allows interoperability
     * @param string $flow_id
     *                        The flow the entities should be pulled from
     * @param array  $options
     *                        An array containing the option parameters provided by Drush
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \EdgeBox\SyncCore\Exception\SyncCoreException
     *
     * @deprecated Function is deprecated and is going to be removed in 2.0, use
     *   pull() instead.
     */
    public function pull_entities($io, $flow_id, $options)
    {
        $io->warning('Function is deprecated and is going to be removed in 2.0, use "cs-pull" instead.');

        $force = $options['force'];

        $flows = Flow::getAll();

        foreach ($flows as $id => $flow) {
            if ($flow_id && $id != $flow_id) {
                continue;
            }

            $result = FlowPull::pullAll($flow, $force);

            if (empty($result)) {
                $io->text('No automated pull configured for Flow: '.$flow->label());

                continue;
            }

            $io->text('Started pulling for Flow: '.$flow->label());

            foreach ($result as $operation) {
                $operation->execute();

                if (!($goal = $operation->total())) {
                    $io->text('> Nothing to do for: '.$operation->getTypeMachineName().'.'.$operation->getBundleMachineName().' from '.$operation->getSourceName());

                    continue;
                }

                $progress = 0;

                while ($progress < $goal) {
                    if ($progress > 0) {
                        sleep(5);
                    }

                    try {
                        $progress = $operation->progress();
                    } catch (TimeoutException $e) {
                        $io->text('> Timeout when asking the Sync Core to report on the progress of pulling '.$goal.' '.$operation->getTypeMachineName().'.'.$operation->getBundleMachineName().' from '.$operation->getSourceName().'. Will try again in 15 seconds...');
                        sleep(15);

                        continue;
                    }

                    if ($progress == $goal) {
                        $io->text('> Finished '.$goal.' operations for '.$operation->getTypeMachineName().'.'.$operation->getBundleMachineName().' from '.$operation->getSourceName());
                    } elseif (0 == $progress) {
                        sleep(5);
                    } else {
                        $io->text('> Finished '.$progress.' / '.$goal.' operations for  '.$operation->getTypeMachineName().'.'.$operation->getBundleMachineName().' from '.$operation->getSourceName().': '.floor($progress / $goal * 100).'%');
                    }
                }
            }
        }
    }

    /**
     * Kindly ask the Sync Core to force pull a specific entity.
     *
     * @param ICLIIO $io
     *                            The CLI service which allows interoperability
     * @param string $flow_id
     *                            The flow the entities should be pulled from
     * @param string $entity_type
     *                            The type of the entity that should be pulled
     * @param string $entity_uuid
     *                            The uuid of the entity that should be pulled
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     *
     * @deprecated Function is deprecated and is going to be removed in 2.0, use
     *   pull() instead.
     */
    public function force_pull_entity($io, $flow_id, $entity_type, $entity_uuid)
    {
        $io->warning('Function is deprecated and is going to be removed in 2.0, use "cs-pull" instead.');

        FlowPull::force_pull_entity($flow_id, $entity_type, $entity_uuid);
    }

    /**
     * Push all entities for a specific flow.
     *
     * @param ICLIIO $io
     *                        The CLI service which allows interoperability
     * @param string $flow_id
     *                        The flow the entities should be pulled from
     * @param array  $options
     *                        An array containing the option parameters provided by Drush
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function push($io, $flow_id, $options)
    {
        $push_mode = $options['push_mode'];
        $flows = Flow::getAll();

        if (!is_null($push_mode)) {
            if ('automatic_manual' != $push_mode && 'automatic_manual_force' != $push_mode) {
                $io->error('Invalid value detected for push_mode. Allowed values are: automatic_manual and automatic_manual_force.');

                return;
            }
        }

        foreach ($flows as $id => $flow) {
            if ($flow_id && $id != $flow_id) {
                continue;
            }

            /**
             * @var \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
             */
            $entity_type_manager = \Drupal::service('entity_type.manager');

            foreach ($flow->getEntityTypeConfig(null, null, true) as $config) {
                if ('automatic_manual' == $push_mode || 'automatic_manual_force' == $push_mode) {
                    if (PushIntent::PUSH_AUTOMATICALLY != $config['export'] && PushIntent::PUSH_MANUALLY != $config['export']) {
                        continue;
                    }
                } else {
                    if (PushIntent::PUSH_AUTOMATICALLY != $config['export']) {
                        continue;
                    }
                }

                $storage = $entity_type_manager->getStorage($config['entity_type_name']);

                $query = $storage
                    ->getQuery();

                // Files don't have bundles, so this would lead to a fatal error then.
                if ($storage->getEntityType()->getKey('bundle')) {
                    $query = $query->condition($storage->getEntityType()
                        ->getKey('bundle'), $config['bundle_name']);
                }

                $ids = $query->execute();
                $total = count($ids);

                if (!$total) {
                    $io->text('Skipping '.$config['entity_type_name'].'.'.$config['bundle_name'].' as no entities match.');

                    continue;
                }

                $success = 0;
                $io->text('Starting to push '.$total.' '.$config['entity_type_name'].'.'.$config['bundle_name'].' entities.');

                foreach ($ids as $id) {
                    $entity = $storage->load($id);

                    /**
                     * @var \Drupal\cms_content_sync\Entity\EntityStatus[] $entity_status
                     */
                    $entity_status = EntityStatus::getInfosForEntity($entity->getEntityTypeId(), $entity->uuid(), ['flow' => $flow->id()]);

                    if ('automatic_manual' == $push_mode && (empty($entity_status) || is_null($entity_status[0]->getLastPush()))) {
                        continue;
                    }

                    /**
                     * @var \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
                     */
                    $entity_type_manager = \Drupal::service('entity_type.manager');

                    $entity = $entity_type_manager
                        ->getStorage($config['entity_type_name'])
                        ->load($id);

                    try {
                        PushIntent::pushEntity($entity, PushIntent::PUSH_FORCED, SyncIntent::ACTION_CREATE, $flow);
                        ++$success;
                    } catch (\Exception $exception) {
                        \Drupal::logger('cms_content_sync')
                            ->notice(
                                'Entity could not be pushed, reason: %exception<br>Flow: @flow_id',
                                [
                                    '%exception' => $exception->getMessage(),
                                    '@flow_id' => $flow_id,
                                ]
                            );
                    }
                }

                $io->text('Successfully pushed '.$success.' entities.');

                if ($total != $success) {
                    $io->text('Failed to push '.($total - $success).' entities.');
                }
            }
        }
    }

    /**
     * Reset the status entities for a specific or all pool/s.
     *
     * @param ICLIIO $io
     *                        The CLI service which allows interoperability
     * @param array  $options
     *                        An array containing the option parameters provided by Drush
     *
     * @throws \Drush\Exceptions\UserAbortException
     */
    public function reset_status_entities($io, $options = ['pool_id' => null])
    {
        $pool_id = empty($options['pool_id']) ? null : $options['pool_id'];

        if (empty($pool_id)) {
            $io->warning(dt('Are you sure you want to reset the status entities for all pools?'));
        } else {
            $io->warning(dt('Are you sure you want to reset the status entities for the pool: '.$pool_id.'?'));
        }
        $io->warning(dt('By resetting the status of all entities, the date of the last pull and the date of the last push date will be reset. The dates will no longer be displayed until the content is pulled or pushed again and all entities will be pushed / pulled again at the next synchronization regardless of whether they have changed or not.'));

        if (!$io->confirm(dt('Do you want to continue?'))) {
            throw new UserAbortException();
        }

        empty($pool_id) ? Pool::resetStatusEntities() : Pool::resetStatusEntities($pool_id);
        $io->success('Status entities have been reset and entity caches are invalidated.');
    }

    /**
     * Check the flags for an entity.
     *
     * @param ICLIIO $io
     *                            The CLI service which allows interoperability
     * @param string $entity_uuid
     *                            The uuid of the entity the flags should be checked for
     * @param array  $options
     *                            An array containing the option parameters provided by Drush
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    public function check_entity_flags($io, $entity_uuid, $options = ['flag' => null])
    {
        $flag = empty($options['flag']) ? null : $options['flag'];

        /**
         * @var \Drupal\cms_content_sync\Entity\EntityStatus[] $entity_status
         */
        $entity_status = \Drupal::entityTypeManager()
            ->getStorage('cms_content_sync_entity_status')
            ->loadByProperties(['entity_uuid' => $entity_uuid]);
        if (empty($entity_status)) {
            $io->text(dt('There is no status entity existent yet for this UUID.'));
        } else {
            foreach ($entity_status as $status) {
                $result = '';
                $io->text(dt('Flow: '.$status->get('flow')->value));

                if (empty($flag)) {
                    $result .= 'FLAG_IS_SOURCE_ENTITY: '.($status->isSourceEntity() ? 'TRUE' : 'FALSE').PHP_EOL;
                    $result .= 'FLAG_PUSH_ENABLED: '.($status->isPushEnabled() ? 'TRUE' : 'FALSE').PHP_EOL;
                    $result .= 'FLAG_PUSHED_AS_DEPENDENCY: '.($status->isPushedAsDependency() ? 'TRUE' : 'FALSE').PHP_EOL;
                    $result .= 'FLAG_EDIT_OVERRIDE: '.($status->isOverriddenLocally() ? 'TRUE' : 'FALSE').PHP_EOL;
                    $result .= 'FLAG_USER_ENABLED_PUSH: '.($status->didUserEnablePush() ? 'TRUE' : 'FALSE').PHP_EOL;
                    $result .= 'FLAG_DELETED: '.($status->isDeleted() ? 'TRUE' : 'FALSE').PHP_EOL;
                } else {
                    switch ($flag) {
            case 'FLAG_IS_SOURCE_ENTITY':
              $status->isSourceEntity() ? $result .= 'TRUE' : $result .= 'FALSE';

              break;

            case 'FLAG_PUSH_ENABLED':
              $status->isPushEnabled() ? $result .= 'TRUE' : $result .= 'FALSE';

              break;

            case 'FLAG_PUSHED_AS_DEPENDENCY':
              $status->isPushedAsDependency() ? $result .= 'TRUE' : $result .= 'FALSE';

              break;

            case 'FLAG_EDIT_OVERRIDE':
              $status->isOverriddenLocally() ? $result .= 'TRUE' : $result .= 'FALSE';

              break;

            case 'FLAG_USER_ENABLED_PUSH':
              $status->didUserEnablePush() ? $result .= 'TRUE' : $result .= 'FALSE';

              break;

            case 'FLAG_DELETED':
              $status->isDeleted() ? $result .= 'TRUE' : $result .= 'FALSE';

              break;
          }
                }
                $io->text(dt($result));
            }
        }
    }
}
