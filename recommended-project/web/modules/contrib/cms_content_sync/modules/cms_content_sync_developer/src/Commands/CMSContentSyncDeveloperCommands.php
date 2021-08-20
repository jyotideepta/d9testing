<?php

namespace Drupal\cms_content_sync_developer\Commands;

use Drupal\cms_content_sync_developer\Cli\CliService;
use Drush\Commands\DrushCommands;

/**
 * Content Sync Developer Drush Commands.
 */
class CMSContentSyncDeveloperCommands extends DrushCommands {

  /**
   * The interoperability cli service.
   *
   * @var \Drupal\cms_content_sync_developer\Cli\CliService
   */
  protected $cliService;

  /**
   * CMS Content Sync constructor.
   *
   * @param \Drupal\cms_content_sync_developer\Cli\CliService $cliService
   *   The CLI service which allows interoperability.
   */
  public function __construct(CliService $cliService) {
    $this->cliService = $cliService;

    parent::__construct();
  }

  /**
   * @return \Symfony\Component\Console\Style\SymfonyStyle|ICLIIO
   */
  protected function io() {
    return parent::io();
  }

  /**
   * Export the configuration to the Sync Core.
   *
   * @command cms_content_sync_developer:update-flows
   * @aliases csuf
   */
  public function configuration_export() {
    $this->cliService->configuration_export($this->io());
  }

  /**
   * Force the deletion of entities and skip the syndication.
   *
   * @command cms_content_sync_developer:force-entity-deletion
   *
   * @aliases csfed
   *
   * @param string $entity_type
   *   The entity type the entities should be deleted for.
   * @param array $options
   *
   * @options bundle
   *  The bundle the entities should be deleted for.
   * @options entity_uuid
   *  The entities uuid that should be deleted.
   *
   * @usage cms_content_sync_developer:force_entity_deletion node --entity_uuid="06d1d5b8-5583-4929-9f7c-c85cfe59440b"
   *  Force delete the node having the uuid: "06d1d5b8-5583-4929-9f7c-c85cfe59440b".
   * @usage cms_content_sync_developer:force_entity_deletion node --bundle="basic_page"
   *  Force delete all nodes having the bundle basic_page.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drush\Exceptions\UserAbortException
   */
  public function force_entity_deletion($entity_type, $options = ['bundle' => NULL, 'entity_uuid' => NULL]) {
    $this->cliService->force_entity_deletion($this->io(), $entity_type, $options);
  }

}
