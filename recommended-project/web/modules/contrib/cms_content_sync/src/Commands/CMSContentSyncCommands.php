<?php

namespace Drupal\cms_content_sync\Commands;

use Drupal\cms_content_sync\Cli\CliService;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Input\InputOption;

/**
 * Content Sync Drush Commands.
 */
class CMSContentSyncCommands extends DrushCommands
{
    /**
     * The interoperability cli service.
     *
     * @var \Drupal\cms_content_sync\Cli\CliService
     */
    protected $cliService;

    /**
     * CMS Content Sync constructor.
     *
     * @param \Drupal\cms_content_sync\Cli\CliService $cliService
     *                                                            The CLI service which allows interoperability
     */
    public function __construct(CliService $cliService)
    {
        $this->cliService = $cliService;

        parent::__construct();
    }

    /**
     * Export the configuration to the Sync Core.
     *
     * @command cms_content_sync:configuration-export
     *
     * @aliases cse csce
     *
     * @options force
     *  Whether to ignore that another site is already using the same site ID.
     *  Useful if you change the URL of a site.
     *
     * @param array $options
     *
     * @throws \Exception
     */
    public function configuration_export($options = ['force' => false])
    {
        $this->cliService->configuration_export($this->io(), $options);
    }

    /**
     * Kindly ask the Sync Core to login again.
     *
     * @command cms_content_sync:sync-core-login
     *
     * @aliases csscl
     */
    public function sync_core_login()
    {
        $this->cliService->sync_core_login($this->io());
    }

    /**
     * Kindly ask the Sync Core to pull all entities for a specific flow, or to
     * force pull one specific entity.
     *
     * @command cms_content_sync:pull
     *
     * @aliases cs-pull
     *
     * @param string $flow_id
     *                        The flow the entities should be pulled from
     * @param array  $options
     *
     * @options force
     *  Also update entities which have already been pulled.
     * @options entity_type
     *  The type of the entity that should be pulled, e.g. "node".
     * @options entity_uuid
     *  The uuid of the entity that should be pulled.
     *
     * @usage cms_content_sync:pull example_flow
     *   Pulls all entities from the example flow.
     * @usage cms_content_sync:pull example_flow --force
     *   Pull all entities from the "example_flow" and force entities which already have been pulled to be updated as well.
     * @usage cms_content_sync:pull example_flow --entity_type="node" --entity_uuid="3a150294-90eb-48c2-911d-672043a45683"
     *   Force pull the node having the uuid 3a150294-90eb-48c2-911d-672043a45683 from the example flow.
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \EdgeBox\SyncCore\Exception\SyncCoreException
     */
    public function pull($flow_id, $options = ['force' => false, 'entity_type' => null, 'entity_uuid' => null])
    {
        $this->cliService->pull($this->io(), $flow_id, $options);
    }

    /**
     * Push all entities for a specific flow.
     *
     * @command cms_content_sync:push
     *
     * @aliases cs-push cms_content_sync:push-entities
     *
     * @param string $flow_id
     *                        The flow the entities should be pushed for
     * @param array  $options
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * @options push_mode
     *  Allows to overwrite the default push mode.
     *
     * @usage cms_content_sync:push example_flow
     *   Push all entities from the "example_flow" which have push configured as "automatically".
     * @usage cms_content_sync:push example_flow --push_mode="automatic_manual"
     *   Push all entities from the "example_flow" which have push configured as "automatically" or "manually". Only exports manually exported entities which have not been exported before.
     * @usage cms_content_sync:push example_flow --push_mode="automatic_manual_force"
     *   Push all entities from the "example_flow" which have push configured as "automatically" or "manually". Also exports entities which have not been exported before by the manual push.
     */
    public function push($flow_id, $options = ['push_mode' => null])
    {
        $this->cliService->push($this->io(), $flow_id, $options);
    }

    /**
     * Kindly ask the Sync Core to pull all entities for a specific flow.
     *
     * @command cms_content_sync:pull-entities
     *
     * @aliases cspe
     *
     * @param string $flow_id
     *                        The flow the entities should be pulled from
     * @param array  $options
     *
     * @options force
     *  Also update entities which have already been pulled.
     *
     * @usage cms_content_sync:pull-entities example_flow
     *   Pulls all entities from the example flow.
     * @usage cms_content_sync:pull-entities example_flow --force
     *   Pull all entities from the "example_flow" and force entities which already have been pulled to be updated as well.
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \EdgeBox\SyncCore\Exception\SyncCoreException
     *
     * @deprecated Function is deprecated and is going to be removed in 2.0, use pull() instead.
     */
    public function pull_entities($flow_id, $options = ['force' => false])
    {
        $this->cliService->pull_entities($this->io(), $flow_id, $options);
    }

    /**
     * Kindly ask the Sync Core to force pull a specific entity.
     *
     * @command cms_content_sync:force-pull-entity
     *
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
     * @usage cms_content_sync:force-pull-entity example_flow node 3a150294-90eb-48c2-911d-672043a45683
     *   Force pull the node having the uuid 3a150294-90eb-48c2-911d-672043a45683 which has been imported from the example flow.
     *
     * @deprecated Function is deprecated and is going to be removed in 2.0, use pull() instead.
     */
    public function force_pull_entity($flow_id, $entity_type, $entity_uuid)
    {
        $this->cliService->force_pull_entity($this->io(), $flow_id, $entity_type, $entity_uuid);
    }

    /**
     * Reset the status entities for a specific or all pool/s.
     *
     * @command cms_content_sync:reset-status-entities
     *
     * @aliases csrse
     *
     * @param array $options
     *
     * @options pool_id
     *  The machine name of the pool the status entities should be reset for.
     *
     * @usage cms_content_sync:reset-status-entities
     *   Reset all status entities for all pools.
     * @usage cms_content_sync:reset-status-entities --pool_id='example_pool'
     *   Reset all status entities for the "example_pool".
     *
     * @throws \Drush\Exceptions\UserAbortException
     */
    public function reset_status_entities($options = ['pool_id' => InputOption::VALUE_OPTIONAL])
    {
        $this->cliService->reset_status_entities($this->io(), $options);
    }

    /**
     * Check the flags for an entity.
     *
     * @command cms_content_sync:check-entity-flags
     *
     * @aliases cscef
     *
     * @param string $entity_uuid
     *                            The entities uuid you would like to check for
     * @param array  $options
     *
     * @options flag The flag to check for, allowed values are: FLAG_IS_SOURCE_ENTITY, FLAG_PUSH_ENABLED, FLAG_PUSHED_AS_DEPENDENCY, FLAG_EDIT_OVERRIDE, FLAG_USER_ENABLED_PUSH, FLAG_DELETED
     *
     * @usage cms_content_sync:check-entity-flags 16cc0d54-d93d-45b8-adf2-071de9d2d32b
     *   Get all flags for the entity having the uuid = "16cc0d54-d93d-45b8-adf2-071de9d2d32b".
     * @usage cms_content_sync:check-entity-flags 16cc0d54-d93d-45b8-adf2-071de9d2d32b --flag="FLAG_EDIT_OVERRIDE"
     *   Check if the entity having the uuid = "16cc0d54-d93d-45b8-adf2-071de9d2d32b" is overridden locally.
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    public function check_entity_flags($entity_uuid, $options = ['flag' => InputOption::VALUE_OPTIONAL])
    {
        $this->cliService->check_entity_flags($this->io(), $entity_uuid, $options);
    }

    /**
     * @return ICLIIO|\Symfony\Component\Console\Style\SymfonyStyle
     */
    protected function io()
    {
        return parent::io();
    }
}
