# Content Sync

## Configuration
Please install the module and visit the Configuration > Web Services > Content Sync page to register your site and start using Content Sync.

## Views integration - Dynamic Entity Reference
To provide a views integration, the Dynamic Entity Reference (https://www.drupal.org/project/dynamic_entity_reference) module is required.
This is required as we store references to multiple entity types within one table. The views integration can be enabled by installing
the submodule "CMS Content Sync Views (cms_content_sync_views)".

## Manual Pull Dashboard - Images
To show images in the Manual Pull Dashboard, we recommend to use the module: Image URL Formatter (https://www.drupal.org/project/image_url_formatter).
The module allows you to use absolute URLs for the images that are rendered in the previews displayed the Manual Pull Dashboard. That way you can create previews of images that are provided by another Drupal site.
