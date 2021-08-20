<?php

namespace Drupal\cms_content_sync\Controller;

use Drupal\Core\KeyValueStore\KeyValueDatabaseFactory;
use Drupal\encrypt\Entity\EncryptionProfile;
use Drupal\user\Entity\User;
use Drupal\user\UserDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class AuthenticationByUser.
 *
 * Ask the Sync Core to use a local user account to authenticate against this site.
 */
class AuthenticationByUser
{
    /**
     * @var string KEY_USERNAME
     */
    public const KEY_USERNAME = 'userName';

    /**
     * @var string KEY_USERNAME
     */
    public const KEY_PASSWORD = 'userPass';

    /**
     * @var \Drupal\Core\KeyValueStore\KeyValueDatabaseFactory
     */
    protected $keyValueDatabase;

    /**
     * @var \Drupal\user\Entity\UserDataInterface
     */
    protected $userData;

    /**
     * Constructs a new EntityListBuilder object.
     *
     * @param \Drupal\user\Entity\UserDataInterface $user_data
     */
    public function __construct(KeyValueDatabaseFactory $key_value_database, UserDataInterface $user_data)
    {
        $this->keyValueDatabase = $key_value_database;
        $this->userData = $user_data;
    }

    /**
     * Singleton.
     *
     * @return AuthenticationByUser
     */
    public static function getInstance()
    {
        static $instance = null;
        if ($instance) {
            return $instance;
        }

        return $instance = self::createInstance(\Drupal::getContainer());
    }

    /**
     * Create a new instance of the controller with the services of the given container.
     *
     * @return AuthenticationByUser
     */
    public static function createInstance(ContainerInterface $container)
    {
        return new static(
      $container->get('keyvalue.database'),
      $container->get('user.data')
    );
    }

    /**
     * @throws \Exception
     *
     * @returns array
     */
    public function getLoginData()
    {
        static $loginData = null;

        if (!empty($loginData)) {
            return $loginData;
        }

        $user = User::load(CMS_CONTENT_SYNC_USER_ID);

        // During the installation from an existing config for some reason CMS_CONTENT_SYNC_USER_ID is not set right after
        // the installation of the module, so we hae to double check that.
        if (is_null(CMS_CONTENT_SYNC_USER_ID)) {
            $user = User::load(
                $this->keyValueDatabase
                    ->get('cms_content_sync_user')
                    ->get('uid')
            );
        }

        if (!$user) {
            throw new \Exception("Content Sync User not found. Encrypted data can't be read or saved.");
        }

        $loginData = $this->userData->get('cms_content_sync', $user->id(), 'sync_data');

        if (!$loginData) {
            throw new \Exception('No credentials for sync user found.');
        }

        $encryption_profile = EncryptionProfile::load(CMS_CONTENT_SYNC_ENCRYPTION_PROFILE_NAME);

        foreach ($loginData as $key => $value) {
            $loginData[$key] = \Drupal::service('encryption')
                ->decrypt($value, $encryption_profile);
        }

        return $loginData;
    }

    /**
     * @return string
     */
    public function getUsername()
    {
        return $this->getLoginData()[self::KEY_USERNAME];
    }

    /**
     * @return string
     */
    public function getPassword()
    {
        return $this->getLoginData()[self::KEY_PASSWORD];
    }
}
