<?php
/**
 * FileStatus.
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
 * FileStatus Class Doc Comment.
 *
 * @category Class
 *
 * @author   OpenAPI Generator team
 *
 * @see     https://openapi-generator.tech
 */
class FileStatus
{
    /**
     * Possible values of this enum.
     */
    public const _100_DRAFT = '100-draft';
    public const _200_UPLOADING = '200-uploading';
    public const _300_SCANNING = '300-scanning';
    public const _400_READY = '400-ready';
    public const _500_UPLOAD_FAILED = '500-upload-failed';
    public const _600_DELETING = '600-deleting';
    public const _700_DELETED = '700-deleted';

    /**
     * Gets allowable values of the enum.
     *
     * @return string[]
     */
    public static function getAllowableEnumValues()
    {
        return [
            self::_100_DRAFT,
            self::_200_UPLOADING,
            self::_300_SCANNING,
            self::_400_READY,
            self::_500_UPLOAD_FAILED,
            self::_600_DELETING,
            self::_700_DELETED,
        ];
    }
}
