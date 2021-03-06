<?php
/**
 * CreateSyndicationDto.
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
 * CreateSyndicationDto Class Doc Comment.
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
class CreateSyndicationDto implements ModelInterface, ArrayAccess, \JsonSerializable
{
    public const DISCRIMINATOR = null;

    /**
     * The original name of the model.
     *
     * @var string
     */
    protected static $openAPIModelName = 'CreateSyndicationDto';

    /**
     * Array of property to type mappings. Used for (de)serialization.
     *
     * @var string[]
     */
    protected static $openAPITypes = [
        'remoteUuid' => 'string',
        'remoteUniqueId' => 'string',
        'entityTypeNamespaceMachineName' => 'string',
        'entityTypeMachineName' => 'string',
        'poolMachineNames' => 'string[]',
        'flowMachineName' => 'string',
        'asDependency' => 'bool',
        'manually' => 'bool',
    ];

    /**
     * Array of property to format mappings. Used for (de)serialization.
     *
     * @var string[]
     * @phpstan-var array<string, string|null>
     * @psalm-var array<string, string|null>
     */
    protected static $openAPIFormats = [
        'remoteUuid' => null,
        'remoteUniqueId' => null,
        'entityTypeNamespaceMachineName' => null,
        'entityTypeMachineName' => null,
        'poolMachineNames' => null,
        'flowMachineName' => null,
        'asDependency' => null,
        'manually' => null,
    ];

    /**
     * Array of attributes where the key is the local name,
     * and the value is the original name.
     *
     * @var string[]
     */
    protected static $attributeMap = [
        'remoteUuid' => 'remoteUuid',
        'remoteUniqueId' => 'remoteUniqueId',
        'entityTypeNamespaceMachineName' => 'entityTypeNamespaceMachineName',
        'entityTypeMachineName' => 'entityTypeMachineName',
        'poolMachineNames' => 'poolMachineNames',
        'flowMachineName' => 'flowMachineName',
        'asDependency' => 'asDependency',
        'manually' => 'manually',
    ];

    /**
     * Array of attributes to setter functions (for deserialization of responses).
     *
     * @var string[]
     */
    protected static $setters = [
        'remoteUuid' => 'setRemoteUuid',
        'remoteUniqueId' => 'setRemoteUniqueId',
        'entityTypeNamespaceMachineName' => 'setEntityTypeNamespaceMachineName',
        'entityTypeMachineName' => 'setEntityTypeMachineName',
        'poolMachineNames' => 'setPoolMachineNames',
        'flowMachineName' => 'setFlowMachineName',
        'asDependency' => 'setAsDependency',
        'manually' => 'setManually',
    ];

    /**
     * Array of attributes to getter functions (for serialization of requests).
     *
     * @var string[]
     */
    protected static $getters = [
        'remoteUuid' => 'getRemoteUuid',
        'remoteUniqueId' => 'getRemoteUniqueId',
        'entityTypeNamespaceMachineName' => 'getEntityTypeNamespaceMachineName',
        'entityTypeMachineName' => 'getEntityTypeMachineName',
        'poolMachineNames' => 'getPoolMachineNames',
        'flowMachineName' => 'getFlowMachineName',
        'asDependency' => 'getAsDependency',
        'manually' => 'getManually',
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
        $this->container['remoteUuid'] = $data['remoteUuid'] ?? null;
        $this->container['remoteUniqueId'] = $data['remoteUniqueId'] ?? null;
        $this->container['entityTypeNamespaceMachineName'] = $data['entityTypeNamespaceMachineName'] ?? null;
        $this->container['entityTypeMachineName'] = $data['entityTypeMachineName'] ?? null;
        $this->container['poolMachineNames'] = $data['poolMachineNames'] ?? null;
        $this->container['flowMachineName'] = $data['flowMachineName'] ?? null;
        $this->container['asDependency'] = $data['asDependency'] ?? null;
        $this->container['manually'] = $data['manually'] ?? null;
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

        if (null === $this->container['entityTypeNamespaceMachineName']) {
            $invalidProperties[] = "'entityTypeNamespaceMachineName' can't be null";
        }
        if (null === $this->container['entityTypeMachineName']) {
            $invalidProperties[] = "'entityTypeMachineName' can't be null";
        }
        if (null === $this->container['flowMachineName']) {
            $invalidProperties[] = "'flowMachineName' can't be null";
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
     * Gets remoteUuid.
     *
     * @return null|string
     */
    public function getRemoteUuid()
    {
        return $this->container['remoteUuid'];
    }

    /**
     * Sets remoteUuid.
     *
     * @param null|string $remoteUuid remoteUuid
     *
     * @return self
     */
    public function setRemoteUuid($remoteUuid)
    {
        $this->container['remoteUuid'] = $remoteUuid;

        return $this;
    }

    /**
     * Gets remoteUniqueId.
     *
     * @return null|string
     */
    public function getRemoteUniqueId()
    {
        return $this->container['remoteUniqueId'];
    }

    /**
     * Sets remoteUniqueId.
     *
     * @param null|string $remoteUniqueId remoteUniqueId
     *
     * @return self
     */
    public function setRemoteUniqueId($remoteUniqueId)
    {
        $this->container['remoteUniqueId'] = $remoteUniqueId;

        return $this;
    }

    /**
     * Gets entityTypeNamespaceMachineName.
     *
     * @return string
     */
    public function getEntityTypeNamespaceMachineName()
    {
        return $this->container['entityTypeNamespaceMachineName'];
    }

    /**
     * Sets entityTypeNamespaceMachineName.
     *
     * @param string $entityTypeNamespaceMachineName entityTypeNamespaceMachineName
     *
     * @return self
     */
    public function setEntityTypeNamespaceMachineName($entityTypeNamespaceMachineName)
    {
        $this->container['entityTypeNamespaceMachineName'] = $entityTypeNamespaceMachineName;

        return $this;
    }

    /**
     * Gets entityTypeMachineName.
     *
     * @return string
     */
    public function getEntityTypeMachineName()
    {
        return $this->container['entityTypeMachineName'];
    }

    /**
     * Sets entityTypeMachineName.
     *
     * @param string $entityTypeMachineName entityTypeMachineName
     *
     * @return self
     */
    public function setEntityTypeMachineName($entityTypeMachineName)
    {
        $this->container['entityTypeMachineName'] = $entityTypeMachineName;

        return $this;
    }

    /**
     * Gets poolMachineNames.
     *
     * @return null|string[]
     */
    public function getPoolMachineNames()
    {
        return $this->container['poolMachineNames'];
    }

    /**
     * Sets poolMachineNames.
     *
     * @param null|string[] $poolMachineNames poolMachineNames
     *
     * @return self
     */
    public function setPoolMachineNames($poolMachineNames)
    {
        $this->container['poolMachineNames'] = $poolMachineNames;

        return $this;
    }

    /**
     * Gets flowMachineName.
     *
     * @return string
     */
    public function getFlowMachineName()
    {
        return $this->container['flowMachineName'];
    }

    /**
     * Sets flowMachineName.
     *
     * @param string $flowMachineName flowMachineName
     *
     * @return self
     */
    public function setFlowMachineName($flowMachineName)
    {
        $this->container['flowMachineName'] = $flowMachineName;

        return $this;
    }

    /**
     * Gets asDependency.
     *
     * @return null|bool
     */
    public function getAsDependency()
    {
        return $this->container['asDependency'];
    }

    /**
     * Sets asDependency.
     *
     * @param null|bool $asDependency asDependency
     *
     * @return self
     */
    public function setAsDependency($asDependency)
    {
        $this->container['asDependency'] = $asDependency;

        return $this;
    }

    /**
     * Gets manually.
     *
     * @return null|bool
     */
    public function getManually()
    {
        return $this->container['manually'];
    }

    /**
     * Sets manually.
     *
     * @param null|bool $manually manually
     *
     * @return self
     */
    public function setManually($manually)
    {
        $this->container['manually'] = $manually;

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
