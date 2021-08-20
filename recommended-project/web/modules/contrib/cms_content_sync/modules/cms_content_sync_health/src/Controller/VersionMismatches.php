<?php

namespace Drupal\cms_content_sync_health\Controller;

use Drupal\cms_content_sync\Entity\Flow;
use Drupal\Core\Url;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Show all version mismatches of all entity types. Can take some time to execute depending on the number of entity
 * types configured, so is run as a batch operation.
 */
class VersionMismatches extends ControllerBase {

  /**
   * Prepare batch operation.
   */
  public function aggregate() {
    $operations = [];

    $entity_types = \Drupal::service('entity_type.bundle.info')->getAllBundleInfo();
    ksort($entity_types);
    foreach ($entity_types as $type_key => $entity_type) {
      if (substr($type_key, 0, 16) == 'cms_content_sync') {
        continue;
      }

      ksort($entity_type);

      foreach ($entity_type as $entity_bundle_name => $entity_bundle) {
        $any_handler = FALSE;
        foreach (Flow::getAll() as $id => $flow) {
          $config = $flow->getEntityTypeConfig($type_key, $entity_bundle_name, TRUE);
          if (empty($config) || $config['handler'] == Flow::HANDLER_IGNORE) {
            continue;
          }
          $any_handler = TRUE;
          break;
        }

        if (!$any_handler) {
          continue;
        }

        $operations[] = [
          '\Drupal\cms_content_sync_health\Controller\VersionMismatches::batch',
          [$type_key, $entity_bundle_name],
        ];
      }
    }

    $batch = [
      'title' => t('Check version mismatches...'),
      'operations' => $operations,
      'finished' => '\Drupal\cms_content_sync_health\Controller\VersionMismatches::batchFinished',
    ];

    batch_set($batch);

    return batch_process();
  }

  /**
   * Batch push finished callback.
   *
   * @param $success
   * @param $results
   * @param $operations
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public static function batchFinished($success, $results, $operations) {
    $list = _cms_content_sync_display_entity_type_differences_recursively_render($results);

    if (empty($list)) {
      \Drupal::messenger()->addStatus(\Drupal::translation()->translate("No differences found; all other connected sites use the same entity type definition as this site."));
    }
    else {
      \Drupal::messenger()->addError(\Drupal::translation()->translate("Some connected sites use other entity type definitions than this site."));
      \Drupal::messenger()->addError($list);
    }

    return RedirectResponse::create(Url::fromRoute('entity.cms_content_sync.sync_health')->toString());
  }

  /**
   * Batch push callback for the push-all operation.
   *
   * @param $type_key
   * @param $entity_bundle_name
   * @param $context
   */
  public static function batch($type_key, $entity_bundle_name, &$context) {
    $message = 'Checking ' . $type_key . '.' . $entity_bundle_name . '...';
    $results = [];
    if (isset($context['results'])) {
      $results = $context['results'];
    }

    _cms_content_sync_display_entity_type_differences_recursively($results, $type_key, $entity_bundle_name);

    $context['message'] = $message;
    $context['results'] = $results;
  }

}
