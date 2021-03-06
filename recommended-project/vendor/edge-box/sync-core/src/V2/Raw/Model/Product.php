<?php
/**
 * Product.
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
 * Product Class Doc Comment.
 *
 * @category Class
 *
 * @author   OpenAPI Generator team
 *
 * @see     https://openapi-generator.tech
 */
class Product
{
    /**
     * Possible values of this enum.
     */
    public const STAGING = 'staging';
    public const SYNDICATION = 'syndication';

    /**
     * Gets allowable values of the enum.
     *
     * @return string[]
     */
    public static function getAllowableEnumValues()
    {
        return [
            self::STAGING,
            self::SYNDICATION,
        ];
    }
}
