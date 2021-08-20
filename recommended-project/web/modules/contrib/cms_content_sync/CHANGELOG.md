# Changelog

## 2.0.2
- fix(DebugForm): Use method to get embeded entities data.
- fix(crop): Fix notice if setting is not existend.
- fix(flow): Handle uninstalled entity types.
- refactor(codestyle): Do not move semicolon to a new line.

## 2.0.1
- feat(Flow): Delete unsed remote flows after config deletion.
- feat(flow): Disable flows in the sync core when they are disabled locally.
- feat(flow): Ensure to only delete unused flows while handling flow entities.
- fix(rest): Keep identical namees between route and method params.
- fix(sync-core): Report correct drupal version.
- fix(file): Ensure that at least one crop bundle is enabled if export_crop is set.
- fix(delete): Fix deletions not working sometimes if entity types are overloaded.
- refactor(codestyle)

## 2.0
- feat(syndication): view the syndication progress live.
- feat(migration): migrate existing data with a user-friendly ui.
- feat(pull-dashboard): provide additional filters.
- feat(embed): many of the interactive parts of Content Sync are now an embedded React frontend.
- feat(performance): the new Sync Core is fully horizontally scalable so can handle hundreds of updates to hundreds of sites.
- feat(registration): you no longer need to reach out to us to get your own Sync Core. Just follow the registration link and get started within minutes.

## 1.47
- fix(embedded_files): Improve directory handling for in text embedded files.

## 1.46
- fix(revisions): Ensure to set the correct revision log message.
- fix(push): Add information to user message when an entity is force pushed.
- fix(cross_sync): Show pool selection also for imported entities to simplify cross sync handling.
- fix(cross_sync): Prevent entities to be exportable if they have been imported and are set to "allow_override", "force_and_forbid_editing" or "pull_update_unpublished".
- fix(EntityUpdateBehavior): Removed duplicated revision handling which caused an issue with files not being imported during the initial pull.
- fix(SyncCoreLibrary): Adjust version constraint.

## 1.45
- feat(debug): Add extended_entity_export_logging option.
- feat(debug): Extend entity debug form to show current entity values.
- feat(debug): Add extended_entity_import_logging option and reformat code of the debug form.
- feat(author): Add configuration option to always use the content sync user as the author of an imported entity.
- feat(sync health): Add UUID field and filter to sync health view page.
- feat(drush): Extend force entity deletion to support menu items.
- feat(export): Check for a valid site baseUrl before exporting pools or flows.
- fix(content-dashboard_filter): direct sync core communication returned no results.
- fix(ContentSyncSettings): Fix direct sync core communication checkbox not being shown as active after enabling it.
- fix(revisions): Ensure to set the revision_translation_affected property and the correct user while handling revisions.
- fix(revisions): Ensure to always set the correct revision timestamp and author.

## 1.44
- refactor(rest): To match Drupal 9 requirements.
- fix(push): Prefer parent pool when pushing references.

## 1.43
- feat(key_value_field): Added support.
- fix(export): Fix count warnings during flow and pool export.
- fix(entity_deletion): Fix a bug that prevented entity deletion when the cms_content_sync_developer submodule wasn't enabled.

## 1.42
- feat(svg_image_field): Added support.
- feat(content_dashboard): Add spinner icon while pulling an entity.
- feat(drush): Add drush command to force entity deletion to the developer submodule.
- feat(validation): Extend validation message to provide the user a better feedback which site is causing the validation error.
- refactor(sync-core): Move sync core library to external package.
- refactor(sync-core): Add version related dependency for the sync core library.
- refactor(drush): Refactor developer submodule to use CLIService.
- refactor(FlowForm): Hide push/pull column for fields since it is not necessary anymore.

## 1.41
- feat(paragraph): Show a warning if a paragraph allows pool assignments but has no pool assigned.
- feat(sync-core): Allow to configure a custom sync core timeout.
- feat(site-id): Allow to reset the site id completely.
- feat(syndication): Add option to embed entities to save on the number of requests required to syndicate content.
- feat(dynamic_entity_reference): Added support.
- feat(block_field): Added support.
- refactor(drush): Refactor Drush implementation to use the newly introduced CliService class as a base service for Drush 8 and Drush 9/10 to reduce code duplication.
- refactor(drush): Merge Drush commands pull_entities() and force_pull_entity() into the newly introduced function pull() and mark the old functions as deprecated.
- refactor(drush): Rename Drush command for pushing entities to cs-push to match naming convention with cs-pull.
- fix(flow): Set assignment of pool to forbid by default for existing flows.
- fix(push): Push creation of menu items which are referenced by another entity.
- refactor(logging): Add flow and pool information to Drupal log messages.
- docs(drush): Adjust command documentation.
- chore(logging): Add log messages for failed push attempts to watchdog.
- fix(FlowForm): Prevent entity type bundles from being opened automatically if they are set to ignore while checking for new fields.
- fix(drush): Fix login command in refactored drush command class.
- refactor(debug): Use sync-core interfaces directly rather than the flow export class.
- fix(debug): Show dependencies of the requested entity as well.
- fix(syndication): Allow the sync to be retried with different options at a later point if the handler denied the pull.
- fix(paragraph): Don't syndicate the parent_id property.
- fix(paragraphs): Ignore paragraphs parent_id as it is a reference id.
- fix(field_collections): Drop support for field collections in combination with draggable views which caused an exception when used together.
- fix(revisions): Import revisions as unpublished if configured within the import flow.
- fix(push): Ensure to not pull unpublished nodes if configured within the import flow.

## 1.40
- Refactor: Changelog from text to markdown.
- Fix: Remove unnecessary comma in Drush 8 command file.

## 1.39
- Fix: Don't push references which are not dependencies.

## 1.38
- Improve nested entity syndication by saving an entity hash per reference field, not needing the entity's changed timestamp anymore.
- Add UI for changing pool assignments for a whole Flow at once.
- Add commands for Drush 8 & 9 to force pull a specific entity.
- Move remote accessibility check from Sync Core to the local site from Pool creation to pulling Flow creation.
- Hide pool selection for paragraphs if content has been pulled from another site.
- Fixed unnecessary form values being syndicated for file entity references.
- Fixed incorrect pool assignment of parent paragraph for nested paragraphs.
- Fixed incorrect Flow export when using multiple Flows with the same entity type.
- Fixed incorrect Flow export creating identical entity types twice.

## 1.37
- Added support for media embed on top of entity embed for formatted text (CKEditor).
- Added deletion of referenced entities to be pushed immediately.
- Added polyfills on the manual import dashboard for IE support.
- Removed the sync core credentials from the manual import dashboard.
- Fixed an issue with the core layout builder implementation.
- Fixed paragraphs that are assigned a pool manually not being added as a dependency.

## 1.36
- Added support for Add to Calendar Button module.
- Added support for Easychart module.
- Fixed an issue that prevented menu links from being disabled.
- Replaced deprecated entity.manager calls.

## 1.35
- Added support for the redirect module.
- Added support for the tablefield module.
- Added support for the yoast seo module.
- Added support for the classy paragraphs module.
- Added support for private files.
- Added new option to pull nodes unpublished.
- Fixed Sync Core being stuck in license check after entity types were removed.
- Fixed FORCE option not working when exporting a Pool.
- Fixed references being added as dependencies incorrectly.
- Fixed 'Enable all' not respecting the allowed bundles for brick references.
- Fixed force pull of individual items always assuming it was manual in the sync health view.

## 1.34
- Flows now behave completely independent of other Flows both in the Sync Core and the module. Before this, they could interfere with each other and lead to content not being syndicated or not being syndicated correctly in complex setups.
- Now allowing entities that are set to be pushed "manually" to be used for "as dependency" push operations.
- Added support for viewsreference module.
- Added "Enable all" for webforms in the Flow form.
- Added hook_requirements to provide health status overview on Drupal's status page.
- Added exception when deleting local entities that should be syndicated if deletion syndication fails.
- Added support to syndicate and update the "created" (authored on) date of nodes.
- Added support for the 3.x version of simple sitemap.
- Added support for "quick edit" on bricks.
- Made "deletion" settings for push and pull default to YES. Now also copying "deletion" settings when copying a remote Flow.
- Improved performance with a new index on the entity status table.
- Improved installation procedure of the views submodule for updating the entity status.
- Improved some user and error messages.
- Removed option to create Flows that both Push and Pull to reduce complexity in new setups.
- Refactored Site ID and authentication type to be a setting per site, not per pool. Added setting for the site name. Will completely remove the site machine name with the 2.0 release.
- Refactored usage of the Sync Core to only use internal interfaces that will be moved to an independent library in the next release, preparing for the 2.0 release of the Sync Core.
- Now automatically expanding entity types in the Flow form to include entity type version changes immediately.
- Fixed simple sitemap not pushing settings when pushed manually.
- Fixed notice for removed fields and exception for removed reference fields.
- Fixed timeout for "Pull: All" operation when Sync Core was unresponsive due to high load.
- Fixed exception when configuring Flows with webforms.
- Fixed menu items not being syndicated on node creation.
- Fixed exception during the Ping request when creating a new Pool.

## 1.33
- Now using "Pull" and "Push" consistently throughout the module for entity operations instead of mixed Import/Pull and Export/Push.
- Improved Sync Core startup and syndication performance by 15% for customers with many sites.
- Improved performance of manual import dashboard by 80% for customers with many content items.
- Setting the Pool configuration from Force to Allow automatically for manual pulls.
- Added ping check to Sync Core to ensure the site is accessible from the outside (Sync Core).
- Removed version constraint from Pull dashboard.
- Added dynamic naming of settings tab and import tab based on whether the site is connected to a SaaS Sync Core (Content Cloud) or an on-premise Sync Core (Content Repository).
- Refactored Authentication Controller and Settings Controller.
- Refactored module to use Sync Core library consistently throughout the module.
- Added support to Pull Dashboard to enable direct Browser <> Sync Core communication for quicker loading times if using a SaaS Sync Core.
- Now hiding the Show usage button when creating new entities.
- Added support to pass Drupal 9 readiness check.
- Now showing the total number of matching items in the Pull Dashboard.
- Improved syndication by making Flows behave autonomously to each other.
- Added validation that an individual Flow only contains Pools from one Sync Core.
- Added validation that files exist in the local filesystem prior to pushing them.
- Added validation that Flows aren't updated unless they're all up-to-date.
- Improved Push All performance by using multiple batch operations.
- Improved Sync Health loading time by making the version mismatch action a batch operation.
- Added support for manually imported entities when pulling all.
- Fixed timeout issue with Pull All and improved Pull All performance in the Sync Core.
- Fixed an issue where referenced entities sometimes wouldn't update until a restart of the Sync Core.
- Fixed handling of file URLs in formatted text:
  - Fixed file URL's not being matched correctly.
  - Preventing broken HTML even for missing files now.
  - Added support for filenames containing spaces
  - Added support for links to files containing an anchor like example.pdf#page=2
  - Keeping file URIs relative now when pulling and replacing them.
- Fixed fatal error on Sync Health > Push page when pushing certain bibcite entities.
- Fixed "duplicate uuid" issue when pulling files that already existed before installing Content Sync.
- Fixed too many entity types being shown in the Pull Dashboard.
- Fixed items showing up in the Pull Dashboard that are forbidden by a "Subscribe only to" filter.
- Fixed bricks not being syndicated when edited inline.
- Fixed fatal error when copying a Flow from a remote site that contains entity types or fields that aren't available on the local site.

## 1.32
- Added Drush commands for "Push all" entities for Drush 8 and 9.
- Optimized loading times of the import dashboard by decreasing the pager size to 5.
- Made the search on the import dashboard case insensitive.

## 1.31
- Added support for draggableviews.
- Added capability to clone an existing flow from another site.
- Improved Pool add form.
- Added the functionality to copy already existent pools from the sync core.
- Set default pool name / id to Content / content.
- Removed redundant sanity check.
- Added link to create a flow after creating a pool if no flows exist yet.
- Added links to copy a flow from an existing site to message after module install and pool save.
- Added field descriptions to pool name and id.
- Fixed issue that deleted content could not be re-imported when importing manually.
- Migrated deprecated function calls.
- Refactored cms_content_sync_health and cms_content_sync_developer submodule to use dependency injection.
- Renamed CMS Content Sync to Content Sync in the UI.
- Increased max length of the Subscribe only to filter to avoid default restriction.
- Export manually as well if the entity was scheduled.
- Fixed an issue with embedded files within the ckeditor.

## 1.30
- Added support for remote media entities.
- Fixed incorrect warning message about content being deleted from other sites if the content pool didn't match the allowed export pool of the Flow.
- Fixed import dashboard showing items that should be excluded by the 'subscribe only to' setting.
- Removed deprecated js dependency.

## 1.29
- Fixed an error within the Acquia Content Hub Migration module.
- Fixed several notices on unset variables.
- Fixed "Push all" for files having the configuration "Export - All".

## 1.28
- Refactored flow form to support sites with 150+ entity types.
- CMS Content Sync Developer: Added an expert flow form to speed up the initial creation of flows.

## 1.27
- Added support for the Bibcite (https://www.drupal.org/project/bibcite).
- Fixed author reference.

## 1.26
- Added support for the core layout builder.

## 1.25
- Improved support for Webforms.
- Fixed bug that Focal Point was relying on the field name "field_media_image" for media elements.

## 1.24
- Improved Crop Entity handling.
- Fixed UUID error from files when they are updated.

## 1.23
- Added support for Focal Point.
- Added functionality to import references with mismatching versions.
- Extended the "Flow - Push all" functionality to allow users to select a push mode.
- Fixed routing permissions.

## 1.22
- Added support for IMCE.
- Improved Drush command help text.
- Fixed an issue with the user_revision field for core versions before 8.7.x.
- Fixed notices for non existent pools.

## 1.21
- Added support for entity embed in CKEditor.
- Fixed an issue when the CMS Content Sync user did not exist on the importing site.
- Fixed notices on while importing entities.

## 1.20
- Added option to filter content import by taxonomy terms.
- Updated acquia content hub migration to use new taxonomy term filter.
- Fixed implementation of "Save and export" node action.

## 1.19
- Added support for panelizer, video and social_media modules
- Added option to export links with their absolute URL only
- Added option to map taxonomy terms by their name, not only their UUID to keep them unique per site
- Added workaround for taxonomy bug: module returns string instead of int for creation date
- Improved deletion handling of manually imported content, allowing content to be re-imported after deletion
- Now allowing to edit menu items when editing a node even if the node itself cannot be edited
- Acquia Content Hub migration: Fixed error for disabled entity types
- Fixed Pool export issue when Pools were used across multiple Flows

## 1.18
- Added option to allow reimport of entities which had already been imported from the content dashboard but were deleted manually afterwards
- Added Drush 9 support for the command "update-flows"
- Added "Save and export" button to entity types which are configured to be exported manually
- Added a change for the submit button label if the entity is configured to be exported automatically
- Added support for IMCE
- Improved content dashboard styles
- Fixed issue with the form library inclusion
- Fixed the Drush command to update all flows by extending forbidden fields to also respect entity type properties
- Fixed language duplicates when using the sync state views filter
- Fixed notices within the flow form
- Fixed fatal error during config import
- CMS Content Sync Migrate Acquia Content Hub: Added Drush support for the Migration from Acquia Content Hub to CMS Content Sync
- CMS Content Sync Health: Added aggregation to avoid translation related duplicates

## 1.17
- Added support for webforms as first Config entity
- Added smarter dependency management to resolve entity references as soon as the entities become available
- Added support to force pull all entities for Flows
- Added support for resetting overridden entities
- Added support for yearonly field
- Added "Show usage" and "Push changes" links to "Sync Status" views field
- Added migration path from Acquia Content Hub
- Added pool filter when editing an entity if more than 10 pools are available
- Improved version handling at the Sync Core for significantly better performance
- Simplified Flow form by hiding 80%-90% of all settings by default
- Improved user messages
- Improved paragraph field widget support to cover custom paragraph widgets
- Fixed notices and improved codestyle
- Fixed issue with overwrites when using the same entity in multiple Flows
- Fixed issue with taxonomy tree weights
- Fixed issue with dynamic entity reference at status entities not being set for cross sync
- Fixed issue with "is source site" flag not being set correctly for cross sync
- Fixed issue with entities being listed as dependencies even without "Export referenced entity" being set
- Improved Flow overview description below the table

## 1.16
- Added support for field collection translations.
- Added fix for nested field collections.
- Added content title filter to health dashboard - entity status view.
- Added fix to skip reexport of imported entities if the update behavior is set to UPDATE_FORCE_AND_FORBID_EDITING or UPDATE_UNLESS_OVERRIDDEN.
- Added Drush commands to check for entity flags for Drush 8+9.
- Added functionality to show version mismatches on the flow overview page.
- Added deletion for related status entities on pool deletion.
- Added check to prevent bulk deletion if imported entities cannot be deleted.
- Extended views sync state field to mark locally overridden entities.
- Improved entity status views integration.
- Improved views integration to filter for locally overridden entities.
- Improved Taxonomy handler to ensure that the parent entity exists.
- Improve logging.
- Fixed notice for nested referenced entities.
- Fixed an issue with disabled Flows or Pools still being referenced
- Fixed exception by saving status entities after setting their timestamps.
- Issue #3035787 by chipway: All dependencies must be prefixed

## 1.15
- Improved file handler to allow any field properties
- Fixed field collections implementation that broke the Default Entity Handler

## 1.14
- Added support for Config Pages - https://www.drupal.org/project/config_pages
- Added support for Entity Construction Kit (ECK) - https://www.drupal.org/project/eck
- Added support for Translatable menu link uri - https://www.drupal.org/project/translatable_menu_link_uri
- Added the possibility to save a flow without exporting it.
- Added error handler to ->serialize() function on export.
- Added log message filter for Sync Core to display messages regarding the current site only.
- Improved UX for flow status in Flow list when overridden.
- Removed preview generation for entities unless the preview option was explicitly set.
- Adjusted Drush command description for "drush content-sync-pull-entities"
- Refactored selection of export Flows to improve UI display of push actions.
- Fixed module update message always being shown on health dashboard.
- Fixed an issue that lead to the export of referenced entities although they have been marked as overwritten locally.
- Fixed an issue that lead to a fatal error on the health dashboard status entity overview when no flows or pools exists.
- Fixed an issue that pools that were not set to import manually were shown at the import dashboard.
- Fixed the default value for the mergeable entity reference field handler.
- Fixed reset status action for sync health dashboard.
- Fixed loading of referenced entities during import.
- Fixed an error at overview pages for entities that don't have a UUID.
- Fixed an issue with the deletion of translations.

## 1.13
- Added "Health Dashboard" with different levels of detail for more transparency over the synchronization. Just enable the health submodule to use it.
- Added list of supported entity types on the site to the Introduction page.
- Added "Push All" action to Flows.
- Removed export of disabled Flows.
- Added various flags to the status entity to indicate different kinds of failures for the Health Dashboard.
- Added retry handler for failed 404 requests.
- Added support for the "iframe" and "range" module.
- Hiding entity types from the Flow form that are not supported due to missing entity type handler or missing field handlers for required fields.
- Fixed validation against maximum Collection name length in the Sync Core.
- Fixed greedy Pool dependency from Flows that caused Flows to be deleted when Pools were deleted.
- Fixed issue with multilingual menu items not being exported on first try.
- Fixed issue with menu items not being exported on first try if "export" is set to "all".
- Fixed Flows not being available if they were only activated via settings.php.
- Various fixes for notices.

## 1.12
- Refactored views filter implementation.
- Added views filter option for "Update waiting to be exported".
- Added support for number fields.
- Added support for daterange fields.
- Added permission control for the import dashboard at the settings form.
- Added "Show usage" operation on entity overview pages.
- Fixed overridden "status" value at flow forms.
- Fixed DELETE returning 404 when entity type version changed without entity update.
- Fixed notice when saving entities without value assignment.
- Fixed issue with pools failing export when they were exported without base_url before.
- Fixed dependency bug that would delete Flows when Pools are deleted.

## 1.11
- Added support for the "Simple Sitemap" module
- Added support for pathauto.
- Added field reference handler for views and blocks
- Added support for field collections
- Added permission for the "Show version mismatches" button
- Added events to allow other modules to add additional meta information per entity to export and import.
- Refactored menu synchronization to avoid intransparent dependency on "tokens" module
- Switched from blacklisting to whitelisting for entity types to avoid confusion
- Improved language handling with the "langcode" property
- Fixed bug that prevented changing the language of an already existing entity which does not have a translation yet.
- Fixed bug that didn't display menu items as disabled on content slaves when editing a content
- Fixed display of referenced bundles when editing a flow

## 1.10
- Improved Drush commands naming
- Added module version to reporting
- Added action to pools to reset status entities
- Added Drush command to reset status entities for all or a specific pool
- Added option to node handler to allow explicit unpublishing
- Improved menu handler: If no menu is selected at the "restrict menu" option, all menus are allowed
- Added validation to prevent AJAX errors at the Flow form
- Now explicitly allowing entity path (alias) update
- Added button to show usage of an entity when editing it
- Added button to show version differences per entity type at the Flow form and Entity edit form, showing field differences
- Added the possibility to set flows active/inactive

## 1.9
- Added the possibility to pull all available entities from a flow
  - via the UI
  - via Drush 8 & 9
- Added functionality to reset entities when the overwrite checkbox gets unset
- Fixed a bug with the --force option for the Drush 8 command "drush content-sync-export"
- Improved permission handling for push operation
- Improved import Dashboard by adding filters and sort functionality
- Improved the message that gets shown when no entity export is required
- Added validation that prevents pool deletion if a pool is used within a flow
- Fixed a configuration file name
- Added basic auth as login option for the Sync Core
- Improved install routine by allowing entities to be created during installation

## 1.8
- Added validation for CMS Content Sync Base URL during Drush export
- Removed caching for views field integration
- Added event dispatcher after entity export/import
- Views integration - Ensure that the synced entity is set
- Added Drush 9 support
- Adjusted taxonomy handling to new Drupal 8.6 table schema
- Added button and drush command to login to all connections at the sync core
- Added check to ensure the site id does not already exist in the sync core
- Added support for address field
- Fixed bug that caused an incorrect pool assignment when paragraphs were added, removed and reordered simultaneously
- Fixed function nesting exception in combination with the conditional fields module

## 1.7
- Improved views integration
- Changed "Manual Import Dashboard" date format
- Added validation for CMS Content Sync base url link
- Added version upgrade validation
- Fixed Flow form display error
- Fixed entity type version comparison

## 1.6
- Added stable version of the manual content import dashboard
- Added media entity handler
- Improved javascript to also hide field groups if a entity is not flagged as overwritten
- Updated manual export to allow force push of entities

## 1.5
- Renamed and refactor to CMS Content Sync
- Improved "Manual Import Dashboard"
- Added submodule "CMS Content Sync - Developer"
- Added check for configuration changes within submodule "CMS Content Sync - Developer"
- Added menu link handler

## 1.4
- Added manual import handler and dashboard in BETA
- Added user reference field handler
- Added support for Bricks (https://www.drupal.org/project/bricks)
- Refactored entity reference handler
- Fixed taxonomy tree movements
- Fixed various minor bugs

## 1.3
- Added "Taxonomy Term" entity handler to resolve hierarchy issues
- Fixed export for changed pools sometimes being ignored
- Fixed cross sync re-exporting the same entity again when being changed
- Fixed "overwrite" checkbox sometimes not being saved for nested paragraphs
- Added bulk export for configuration

## 1.2
- Added check if a default value for a pool within the flow form is already set, if not the pool is set to forbidden
- Automatically add all pools as dependencies to flow configurations to ensure that pools are imported before flows
- Changed base field definition of meta info for "last_import" and "last_export" from integer to timestamp
- Added submodule "CMS Content Sync Views" to provide a views integration for meta info entities
- Updated Paragraphs version hint. The dev version is not longer needed since this commit got merged to the latest stable version 8.x-1.3: https://www.drupal.org/project/paragraphs/issues/2868155#comment-12610258
- Added batch export operation for "Pool" and "Flow" export to avoid timeouts while exporting within the user interface
- Added color_field_type field handler
- Added path handler
- Added support for menu_token
- Removed unused code

## 1.1
- Improved robustness by adding various sanity checks
- Fixed bug that may lead to some pools not being used on export if multiple pools are used for the same entity
- Fixed bug that lead to incorrect default "override" checks when using nested paragraphs with subform editing
- Fixed bug that may lead to incorrect language handling if only one language was available on subsites
- Improved codestyle
- Improved documentation
- Added field handler for "telephone", "soundcloud", "color_field_type", "path" and "menu_tokens"
- Added "Debug" tab, allowing you to check all sync data of a specific entity, including it's child data
- Added option to "Disable optimizations", allowing you to sync content completely regardless of state. May be useful if your software encounters a bug and doesn't save data correctly or if you had to change / reset content and want it all to be back up again.

## 1.0
- Initial release
