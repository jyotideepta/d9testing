<?php
/**
 * SiteRestUrls.
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

use ArrayAccess;
use EdgeBox\SyncCore\V2\Raw\ObjectSerializer;

/**
 * SiteRestUrls Class Doc Comment.
 *
 * @category Class
 *
 * @author   OpenAPI Generator team
 *
 * @see     https://openapi-generator.tech
 * @implements \ArrayAccess<TKey, TValue>
 * @template TKey int|null
 * @template TValue mixed|null
 */
class SiteRestUrls implements ModelInterface, ArrayAccess, \JsonSerializable
{
    public const DISCRIMINATOR = null;

    /**
     * The original name of the model.
     *
     * @var string
     */
    protected static $openAPIModelName = 'SiteRestUrls';

    /**
     * Array of property to type mappings. Used for (de)serialization.
     *
     * @var string[]
     */
    protected static $openAPITypes = [
        'retrieveEntity' => 'string',
        'listEntities' => 'string',
        'createEntity' => 'string',
        'deleteEntity' => 'string',
    ];

    /**
     * Array of property to format mappings. Used for (de)serialization.
     *
     * @var string[]
     * @phpstan-var array<string, string|null>
     * @psalm-var array<string, string|null>
     */
    protected static $openAPIFormats = [
        'retrieveEntity' => null,
        'listEntities' => null,
        'createEntity' => null,
        'deleteEntity' => null,
    ];

    /**
     * Array of attributes where the key is the local name,
     * and the value is the original name.
     *
     * @var string[]
     */
    protected static $attributeMap = [
        'retrieveEntity' => 'retrieveEntity',
        'listEntities' => 'listEntities',
        'createEntity' => 'createEntity',
        'deleteEntity' => 'deleteEntity',
    ];

    /**
     * Array of attributes to setter functions (for deserialization of responses).
     *
     * @var string[]
     */
    protected static $setters = [
        'retrieveEntity' => 'setRetrieveEntity',
        'listEntities' => 'setListEntities',
        'createEntity' => 'setCreateEntity',
        'deleteEntity' => 'setDeleteEntity',
    ];

    /**
     * Array of attributes to getter functions (for serialization of requests).
     *
     * @var string[]
     */
    protected static $getters = [
        'retrieveEntity' => 'getRetrieveEntity',
        'listEntities' => 'getListEntities',
        'createEntity' => 'getCreateEntity',
        'deleteEntity' => 'getDeleteEntity',
    ];

    /**
     * Associative array for storing property values.
     *
     * @var mixed[]
     */
    protected $container = [];

    /**
     * Constructor.
     *
     * @param mixed[] $data Associated array of property values
     *                      initializing the model
     */
    public function __construct(array $data = null)
    {
        $this->container['retrieveEntity'] = $data['retrieveEntity'] ?? null;
        $this->container['listEntities'] = $data['listEntities'] ?? null;
        $this->container['createEntity'] = $data['createEntity'] ?? null;
        $this->container['deleteEntity'] = $data['deleteEntity'] ?? null;
    }

    /**
     * Gets the string presentation of the object.
     *
     * @return string
     */
    public function __toString()
    {
        return json_encode(
            ObjectSerializer::sanitizeForSerialization($this),
            JSON_PRETTY_PRINT
        );
    }

    /**
     * Array of property to type mappings. Used for (de)serialization.
     *
     * @return array
     */
    public static function openAPITypes()
    {
        return self::$openAPITypes;
    }

    /**
     * Array of property to format mappings. Used for (de)serialization.
     *
     * @return array
     */
    public static function openAPIFormats()
    {
        return self::$openAPIFormats;
    }

    /**
     * Array of attributes where the key is the local name,
     * and the value is the original name.
     *
     * @return array
     */
    public static function attributeMap()
    {
        return self::$attributeMap;
    }

    /**
     * Array of attributes to setter functions (for deserialization of responses).
     *
     * @return array
     */
    public static function setters()
    {
        return self::$setters;
    }

    /**
     * Array of attributes to getter functions (for serialization of requests).
     *
     * @return array
     */
    public static function getters()
    {
        return self::$getters;
    }

    /**
     * The original name of the model.
     *
     * @return string
     */
    public function getModelName()
    {
        return self::$openAPIModelName;
    }

    /**
     * Show all the invalid properties with reasons.
     *
     * @return array invalid properties with reasons
     */
    public function listInvalidProperties()
    {
        $invalidProperties = [];

        if (null === $this->container['retrieveEntity']) {
            $invalidProperties[] = "'retrieveEntity' can't be null";
        }
        if (null === $this->container['listEntities']) {
            $invalidProperties[] = "'listEntities' can't be null";
        }
        if (null === $this->container['createEntity']) {
            $invalidProperties[] = "'createEntity' can't be null";
        }
        if (null === $this->container['deleteEntity']) {
            $invalidProperties[] = "'deleteEntity' can't be null";
        }

        return $invalidProperties;
    }

    /**
     * Validate all the properties in the model
     * return true if all passed.
     *
     * @return bool True if all properties are valid
     */
    public function valid()
    {
        return 0 === count($this->listInvalidProperties());
    }

    /**
     * Gets retrieveEntity.
     *
     * @return string
     */
    public function getRetrieveEntity()
    {
        return $this->container['retrieveEntity'];
    }

    /**
     * Sets retrieveEntity.
     *
     * @param string $retrieveEntity retrieveEntity
     *
     * @return self
     */
    public function setRetrieveEntity($retrieveEntity)
    {
        $this->container['retrieveEntity'] = $retrieveEntity;

        return $this;
    }

    /**
     * Gets listEntities.
     *
     * @return string
     */
    public function getListEntities()
    {
        return $this->container['listEntities'];
    }

    /**
     * Sets listEntities.
     *
     * @param string $listEntities listEntities
     *
     * @return self
     */
    public function setListEntities($listEntities)
    {
        $this->container['listEntities'] = $listEntities;

        return $this;
    }

    /**
     * Gets createEntity.
     *
     * @return string
     */
    public function getCreateEntity()
    {
        return $this->container['createEntity'];
    }

    /**
     * Sets createEntity.
     *
     * @param string $createEntity createEntity
     *
     * @return self
     */
    public function setCreateEntity($createEntity)
    {
        $this->container['createEntity'] = $createEntity;

        return $this;
    }

    /**
     * Gets deleteEntity.
     *
     * @return string
     */
    public function getDeleteEntity()
    {
        return $this->container['deleteEntity'];
    }

    /**
     * Sets deleteEntity.
     *
     * @param string $deleteEntity deleteEntity
     *
     * @return self
     */
    public function setDeleteEntity($deleteEntity)
    {
        $this->container['deleteEntity'] = $deleteEntity;

        return $this;
    }

    /**
     * Returns true if offset exists. False otherwise.
     *
     * @param int $offset Offset
     *
     * @return bool
     */
    public function offsetExists($offset)
    {
        return isset($this->container[$offset]);
    }

    /**
     * Gets offset.
     *
     * @param int $offset Offset
     *
     * @return null|mixed
     */
    public function offsetGet($offset)
    {
        return $this->container[$offset] ?? null;
    }

    /**
     * Sets value based on offset.
     *
     * @param null|int $offset Offset
     * @param mixed    $value  Value to be set
     */
    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->container[] = $value;
        } else {
            $this->container[$offset] = $value;
        }
    }

    /**
     * Unsets offset.
     *
     * @param int $offset Offset
     */
    public function offsetUnset($offset)
    {
        unset($this->container[$offset]);
    }

    /**
     * Serializes the object to a value that can be serialized natively by json_encode().
     *
     * @see https://www.php.net/manual/en/jsonserializable.jsonserialize.php
     *
     * @return mixed returns data which can be serialized by json_encode(), which is a value
     *               of any type other than a resource
     */
    public function jsonSerialize()
    {
        return ObjectSerializer::sanitizeForSerialization($this);
    }

    /**
     * Gets a header-safe presentation of the object.
     *
     * @return string
     */
    public function toHeaderValue()
    {
        return json_encode(ObjectSerializer::sanitizeForSerialization($this));
    }
}
