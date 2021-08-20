<?php

namespace Drupal\cms_content_sync\Form;

use Drupal\cms_content_sync\Controller\ContentSyncSettings;
use Drupal\cms_content_sync\Controller\Migration;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\Role;
use EdgeBox\SyncCore\Interfaces\IApplicationInterface;

/**
 * Content Sync general settings form.
 */
class SettingsForm extends ConfigFormBase
{
    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'settings_form';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        global $base_url;
        $settings = ContentSyncSettings::getInstance();

        $form['cms_content_sync_base_url'] = [
            '#type' => 'url',
            '#title' => $this->t('Base URL'),
            '#default_value' => $settings->getSiteBaseUrl(true),
            '#description' => $this->t('Do not include a trailing slash. By default the global base_url provided by Drupal is used for the communication between the Content Sync backend and Drupal. However, this setting allows you to override the base_url that should be used for the communication.
      Once this is set, all settings must be exported again. This can be done by either saving them, or using <i>drush cse</i>.<br>The cms content sync base url can also be set in your settings.php file by adding: <i>$settings["cms_content_sync_base_url"] = "http://example.com";</i> to it.'),
            '#attributes' => [
                'placeholder' => $base_url,
            ],
        ];

        $auth_options = [
            IApplicationInterface::AUTHENTICATION_TYPE_COOKIE => $this->t('Standard (Cookie)'),
        ];
        if (\Drupal::service('module_handler')->moduleExists('basic_auth')) {
            $auth_options[IApplicationInterface::AUTHENTICATION_TYPE_BASIC_AUTH] = $this->t('Basic Auth');
        }

        $form['cms_content_sync_authentication_type'] = [
            '#type' => 'select',
            '#title' => $this->t('Authentication'),
            '#default_value' => $settings->getAuthenticationType(),
            '#description' => $this->t('Define how the Sync Core should authenticate against this page. Using Basic Auth can improve startup times when connecting a lot of sites and resolves issues when hiding the default /user/login route for security reasons. When using the SaaS product, we recommend using the standard Drupal login. To enable Basic Auth, please enable the basic_auth module.'),
            '#options' => $auth_options,
            '#required' => true,
        ];

        $id = $settings->getSiteId();
        $site_name = '';

        try {
            $site_name = $settings->getSiteName();
        } catch (\Exception $e) {
            \Drupal::messenger()->addWarning(\Drupal::translation()->translate('Failed to get site name: %error', ['%error' => $e->getMessage()]));
        }

        if ($id) {
            $form['cms_content_sync_name'] = [
                '#type' => 'textfield',
                '#title' => $this->t('Site name'),
                '#default_value' => $site_name,
                '#description' => $this->t(
                    'Any name used to identify this site from other sites. Your site ID is %id.<br>
          The site name can also be set in your settings.php file by adding: <i>$settings["cms_content_sync_site_name"] = "My site name";</i> to it.<br>
          The same works for the site id by using <i>$settings["cms_content_sync_site_id"] = "my_site_id";</i><br>
          <b><i>The site name and site id must only be overwritten <u>after</u> the initial configuration export to the sync core and <u>must</u> always be unique.<br>
          If this is getting ignored, you might run into several issues which are not always undoable without wiping your data from the sync core.</i></b>',
                    ['%id' => $id]
                ),
            ];
        }

        $machine_name = $settings->getSiteMachineName();
        if ($machine_name) {
            $form['cms_content_sync_machine_name'] = [
                '#type' => 'machine_name',
                '#title' => $this->t('Machine name'),
                '#default_value' => $settings->getSiteMachineName(),
                '#disabled' => true,
                '#description' => $this->t('The machine name of this site. Used for legacy reasons; will be removed in a future release.').
                ' '.
                (
                    $id ?
                    $this->t('A unique site ID will be created and stored for identification per site once you export your Pool or Flow configuration.') :
                    $this->t('Your site ID is %id')
                ),
            ];
        }

        if (!Migration::alwaysUseV2()) {
            $embed_entities = $settings->getEmbedEntities();
            $embed_entities_options = [];
            foreach ($embed_entities as $type) {
                $embed_entities_options[$type] = $type;
            }
            $form['cms_content_sync_embed_entities'] = [
                '#type' => 'checkboxes',
                '#title' => $this->t('Embed entities (EXPERIMENTAL)'),
                '#default_value' => $embed_entities_options,
                '#description' => $this->t('If selected, these entity types will be embedded in their parent content that references them. Otherwise, these entities are handled as separate, standalone entities that require one additional request for pushing and pulling each entity. This feature is experimental right now. <strong>Only use this for entity types that are set to pull and push as "referenced" IN ALL FLOWS ON ALL SITES.</strong> Field collections are always embedded and file entities must always be syndicated separately due to their size. This setting is only relevant on sites that PUSH entities.'),
                '#options' => [
                    'block_content' => 'Block content',
                    'paragraph' => 'Paragraph',
                    'bibcite_contributor' => 'Bibcite: Contributor',
                    'bibcite_reference' => 'Bibcite: Reference',
                    'bibcite_keyword' => 'Bibcite: Keyword',
                    'webform' => 'Webform',
                    'media' => 'Media',
                    'taxonomy_term' => 'Taxonomy term',
                ],
            ];
        }

        $form['import_dashboard'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Pull dashboard configuration'),
        ];

        $form['import_dashboard']['cms_content_sync_enable_preview'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Enable preview'),
            '#default_value' => $settings->isPreviewEnabled(),
            '#description' => $this->t('If you want to pull content from this site on other sites via the UI ("Manual" pull action) and you\'re using custom Preview display modes, check this box to actually push them so they become available on remote sites.'),
        ];

        if (_cms_content_sync_is_cloud_version()) {
            $form['import_dashboard']['cms_content_sync_enable_direct_sync_core_access'] = [
                '#type' => 'checkbox',
                '#title' => $this->t('Enable direct Sync Core access'),
                '#default_value' => $settings->isDirectSyncCoreAccessEnabled(),
                '#description' => $this->t('When enabled, users can directly communicate with the Sync Core without Drupal in-between. This can significantly improve the performance of the Pull dashboard as the Sync Core usually only requires 20-30ms to process a request. Please note that this setting is global, so it affects all sites connected to your Sync Core.'),
            ];
        }

        $roles = user_role_names(true);
        // The administrator will always have access.
        unset($roles['administrator']);

        $roles_having_access = [];
        foreach ($roles as $role => $label) {
            // Load role.
            $role = Role::load($role);
            if ($role->hasPermission('access cms content sync content overview') && $role->hasPermission('restful get cms_content_sync_import_entity') && $role->hasPermission('restful post cms_content_sync_import_entity')) {
                $roles_having_access[$role->id()] = $role->id();
            }
        }

        $form['import_dashboard']['access'] = [
            '#type' => 'checkboxes',
            '#title' => $this->t('Access'),
            '#default_value' => $roles_having_access,
            '#description' => $this->t('Please select which roles should be able to access the pull dashboard. This setting will automatically grant the required permissions.'),
            '#options' => $roles,
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        parent::validateForm($form, $form_state);

        $base_url = $form_state->getValue('cms_content_sync_base_url');

        if (!empty($base_url) && '/' === mb_substr($base_url, -1)) {
            $form_state->setErrorByName('cms_content_sync_base_url', 'Do not include a trailing slash.');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        parent::submitForm($form, $form_state);

        $settings = ContentSyncSettings::getInstance();

        $settings->setSiteBaseUrl($form_state->getValue('cms_content_sync_base_url'));
        $settings->setAuthenticationType($form_state->getValue('cms_content_sync_authentication_type'));
        $settings->setPreviewEnabled(
            boolval($form_state->getValue('cms_content_sync_enable_preview'))
        );

        if (!Migration::alwaysUseV2()) {
            $embed_entities_value = $form_state->getValue('cms_content_sync_embed_entities');
            $embed_entities = [];
            foreach ($embed_entities_value as $type => $enable) {
                if ($enable) {
                    $embed_entities[] = $type;
                }
            }
            $settings->setEmbedEntities($embed_entities);
            if (_cms_content_sync_is_cloud_version()) {
                $settings->setDirectSyncCoreAccessEnabled(
                    boolval($form_state->getValue('cms_content_sync_enable_direct_sync_core_access'))
                );
            }
        }

        // Set pull dashboard access permissions.
        $pull_dashboard_access = $form_state->getValue('access');
        foreach ($pull_dashboard_access as $role => $access) {
            // Load role.
            $role_obj = Role::load($role);
            if ($role === $access) {
                $role_obj->grantPermission('access cms content sync content overview');
                $role_obj->grantPermission('restful get cms_content_sync_import_entity');
                $role_obj->grantPermission('restful post cms_content_sync_import_entity');
                $role_obj->save();
            } else {
                $role_obj->revokePermission('access cms content sync content overview');
                $role_obj->revokePermission('restful get cms_content_sync_import_entity');
                $role_obj->revokePermission('restful post cms_content_sync_import_entity');
                $role_obj->save();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames()
    {
        return [
            'cms_content_sync.settings',
        ];
    }
}
