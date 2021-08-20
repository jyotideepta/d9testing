<?php
/**
 * ContractRevisionTransition.
 *
 * PHP version 7.2
 *
 * @category Class
 *
 * @author   OpenAPI Generator team
 *
 * @see     https://openapi-generator.tech
 */

/**
 * Cloud Sync Core.
 *
 * The Sync Core.
 *
 * The version of the OpenAPI document: 1.0
 * Generated by: https://openapi-generator.tech
 * OpenAPI Generator version: 5.2.0
 */

/**
 * NOTE: This class is auto generated by OpenAPI Generator (https://openapi-generator.tech).
 * https://openapi-generator.tech
 * Do not edit the class manually.
 */

namespace EdgeBox\SyncCore\V2\Raw\Model;

/**
 * ContractRevisionTransition Class Doc Comment.
 *
 * @category Class
 *
 * @author   OpenAPI Generator team
 *
 * @see     https://openapi-generator.tech
 */
class ContractRevisionTransition
{
    /**
     * Possible values of this enum.
     */
    public const _NEW = 'new';
    public const RENEWED_AUTOMATICALLY = 'renewed-automatically';
    public const RENEWED_MANUALLY = 'renewed-manually';
    public const REPLACED = 'replaced';

    /**
     * Gets allowable values of the enum.
     *
     * @return string[]
     */
    public static function getAllowableEnumValues()
    {
        return [
            self::_NEW,
            self::RENEWED_AUTOMATICALLY,
            self::RENEWED_MANUALLY,
            self::REPLACED,
        ];
    }
}