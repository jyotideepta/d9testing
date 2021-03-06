<?php
/**
 * FlowEntity.
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
 * FlowEntity Class Doc Comment.
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
class FlowEntity implements ModelInterface, ArrayAccess, \JsonSerializable
{
    public const DISCRIMINATOR = null;

    /**
     * The original name of the model.
     *
     * @var string
     */
    protected static $openAPIModelName = 'FlowEntity';

    /**
     * Array of property to type mappings. Used for (de)serialization.
     *
     * @var string[]
     */
    protected static $openAPITypes = [
        'name' => 'string',
        'machineName' => 'string',
        'containsPreviews' => 'bool',
        'customer' => '\EdgeBox\SyncCore\V2\Raw\Model\DynamicReference',
        'site' => '\EdgeBox\SyncCore\V2\Raw\Model\DynamicReference',
        'project' => '\EdgeBox\SyncCore\V2\Raw\Model\DynamicReference',
        'status' => '\EdgeBox\SyncCore\V2\Raw\Model\FlowStatus',
        'sitePushes' => '\EdgeBox\SyncCore\V2\Raw\Model\FlowSyndication[]',
        'sitePulls' => '\EdgeBox\SyncCore\V2\Raw\Model\FlowSyndication[]',
        'remoteConfigAsFile' => '\EdgeBox\SyncCore\V2\Raw\Model\DynamicReference',
        'id' => 'string',
        'createdAt' => 'float',
        'updatedAt' => 'float',
    ];

    /**
     * Array of property to format mappings. Used for (de)serialization.
     *
     * @var string[]
     * @phpstan-var array<string, string|null>
     * @psalm-var array<string, string|null>
     */
    protected static $openAPIFormats = [
        'name' => null,
        'machineName' => null,
        'containsPreviews' => null,
        'customer' => null,
        'site' => null,
        'project' => null,
        'status' => null,
        'sitePushes' => null,
        'sitePulls' => null,
        'remoteConfigAsFile' => null,
        'id' => null,
        'createdAt' => null,
        'updatedAt' => null,
    ];

    /**
     * Array of attributes where the key is the local name,
     * and the value is the original name.
     *
     * @var string[]
     */
    protected static $attributeMap = [
        'name' => 'name',
        'machineName' => 'machineName',
        'containsPreviews' => 'containsPreviews',
        'customer' => 'customer',
        'site' => 'site',
        'project' => 'project',
        'status' => 'status',
        'sitePushes' => 'sitePushes',
        'sitePulls' => 'sitePulls',
        'remoteConfigAsFile' => 'remoteConfigAsFile',
        'id' => 'id',
        'createdAt' => 'createdAt',
        'updatedAt' => 'updatedAt',
    ];

    /**
     * Array of attributes to setter functions (for deserialization of responses).
     *
     * @var string[]
     */
    protected static $setters = [
        'name' => 'setName',
        'machineName' => 'setMachineName',
        'containsPreviews' => 'setContainsPreviews',
        'customer' => 'setCustomer',
        'site' => 'setSite',
        'project' => 'setProject',
        'status' => 'setStatus',
        'sitePushes' => 'setSitePushes',
        'sitePulls' => 'setSitePulls',
        'remoteConfigAsFile' => 'setRemoteConfigAsFile',
        'id' => 'setId',
        'createdAt' => 'setCreatedAt',
        'updatedAt' => 'setUpdatedAt',
    ];

    /**
     * Array of attributes to getter functions (for serialization of requests).
     *
     * @var string[]
     */
    protected static $getters = [
        'name' => 'getName',
        'machineName' => 'getMachineName',
        'containsPreviews' => 'getContainsPreviews',
        'customer' => 'getCustomer',
        'site' => 'getSite',
        'project' => 'getProject',
        'status' => 'getStatus',
        'sitePushes' => 'getSitePushes',
        'sitePulls' => 'getSitePulls',
        'remoteConfigAsFile' => 'getRemoteConfigAsFile',
        'id' => 'getId',
        'createdAt' => 'getCreatedAt',
        'updatedAt' => 'getUpdatedAt',
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
        $this->container['name'] = $data['name'] ?? null;
        $this->container['machineName'] = $data['machineName'] ?? null;
        $this->container['containsPreviews'] = $data['containsPreviews'] ?? null;
        $this->container['customer'] = $data['customer'] ?? null;
        $this->container['site'] = $data['site'] ?? null;
        $this->container['project'] = $data['project'] ?? null;
        $this->container['status'] = $data['status'] ?? null;
        $this->container['sitePushes'] = $data['sitePushes'] ?? null;
        $this->container['sitePulls'] = $data['sitePulls'] ?? null;
        $this->container['remoteConfigAsFile'] = $data['remoteConfigAsFile'] ?? null;
        $this->container['id'] = $data['id'] ?? null;
        $this->container['createdAt'] = $data['createdAt'] ?? null;
        $this->container['updatedAt'] = $data['updatedAt'] ?? null;
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

        if (null === $this->container['name']) {
            $invalidProperties[] = "'name' can't be null";
        }
        if (null === $this->container['machineName']) {
            $invalidProperties[] = "'machineName' can't be null";
        }
        if (null === $this->container['customer']) {
            $invalidProperties[] = "'customer' can't be null";
        }
        if (null === $this->container['site']) {
            $invalidProperties[] = "'site' can't be null";
        }
        if (null === $this->container['project']) {
            $invalidProperties[] = "'project' can't be null";
        }
        if (null === $this->container['status']) {
            $invalidProperties[] = "'status' can't be null";
        }
        if (null === $this->container['sitePushes']) {
            $invalidProperties[] = "'sitePushes' can't be null";
        }
        if (null === $this->container['sitePulls']) {
            $invalidProperties[] = "'sitePulls' can't be null";
        }
        if (null === $this->container['id']) {
            $invalidProperties[] = "'id' can't be null";
        }
        if (null === $this->container['createdAt']) {
            $invalidProperties[] = "'createdAt' can't be null";
        }
        if (null === $this->container['updatedAt']) {
            $invalidProperties[] = "'updatedAt' can't be null";
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
     * Gets name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->container['name'];
    }

    /**
     * Sets name.
     *
     * @param string $name name
     *
     * @return self
     */
    public function setName($name)
    {
        $this->container['name'] = $name;

        return $this;
    }

    /**
     * Gets machineName.
     *
     * @return string
     */
    public function getMachineName()
    {
        return $this->container['machineName'];
    }

    /**
     * Sets machineName.
     *
     * @param string $machineName machineName
     *
     * @return self
     */
    public function setMachineName($machineName)
    {
        $this->container['machineName'] = $machineName;

        return $this;
    }

    /**
     * Gets containsPreviews.
     *
     * @return null|bool
     */
    public function getContainsPreviews()
    {
        return $this->container['containsPreviews'];
    }

    /**
     * Sets containsPreviews.
     *
     * @param null|bool $containsPreviews containsPreviews
     *
     * @return self
     */
    public function setContainsPreviews($containsPreviews)
    {
        $this->container['containsPreviews'] = $containsPreviews;

        return $this;
    }

    /**
     * Gets customer.
     *
     * @return \EdgeBox\SyncCore\V2\Raw\Model\DynamicReference
     */
    public function getCustomer()
    {
        return $this->container['customer'];
    }

    /**
     * Sets customer.
     *
     * @param \EdgeBox\SyncCore\V2\Raw\Model\DynamicReference $customer customer
     *
     * @return self
     */
    public function setCustomer($customer)
    {
        $this->container['customer'] = $customer;

        return $this;
    }

    /**
     * Gets site.
     *
     * @return \EdgeBox\SyncCore\V2\Raw\Model\DynamicReference
     */
    public function getSite()
    {
        return $this->container['site'];
    }

    /**
     * Sets site.
     *
     * @param \EdgeBox\SyncCore\V2\Raw\Model\DynamicReference $site site
     *
     * @return self
     */
    public function setSite($site)
    {
        $this->container['site'] = $site;

        return $this;
    }

    /**
     * Gets project.
     *
     * @return \EdgeBox\SyncCore\V2\Raw\Model\DynamicReference
     */
    public function getProject()
    {
        return $this->container['project'];
    }

    /**
     * Sets project.
     *
     * @param \EdgeBox\SyncCore\V2\Raw\Model\DynamicReference $project project
     *
     * @return self
     */
    public function setProject($project)
    {
        $this->container['project'] = $project;

        return $this;
    }

    /**
     * Gets status.
     *
     * @return \EdgeBox\SyncCore\V2\Raw\Model\FlowStatus
     */
    public function getStatus()
    {
        return $this->container['status'];
    }

    /**
     * Sets status.
     *
     * @param \EdgeBox\SyncCore\V2\Raw\Model\FlowStatus $status status
     *
     * @return self
     */
    public function setStatus($status)
    {
        $this->container['status'] = $status;

        return $this;
    }

    /**
     * Gets sitePushes.
     *
     * @return \EdgeBox\SyncCore\V2\Raw\Model\FlowSyndication[]
     */
    public function getSitePushes()
    {
        return $this->container['sitePushes'];
    }

    /**
     * Sets sitePushes.
     *
     * @param \EdgeBox\SyncCore\V2\Raw\Model\FlowSyndication[] $sitePushes sitePushes
     *
     * @return self
     */
    public function setSitePushes($sitePushes)
    {
        $this->container['sitePushes'] = $sitePushes;

        return $this;
    }

    /**
     * Gets sitePulls.
     *
     * @return \EdgeBox\SyncCore\V2\Raw\Model\FlowSyndication[]
     */
    public function getSitePulls()
    {
        return $this->container['sitePulls'];
    }

    /**
     * Sets sitePulls.
     *
     * @param \EdgeBox\SyncCore\V2\Raw\Model\FlowSyndication[] $sitePulls sitePulls
     *
     * @return self
     */
    public function setSitePulls($sitePulls)
    {
        $this->container['sitePulls'] = $sitePulls;

        return $this;
    }

    /**
     * Gets remoteConfigAsFile.
     *
     * @return null|\EdgeBox\SyncCore\V2\Raw\Model\DynamicReference
     */
    public function getRemoteConfigAsFile()
    {
        return $this->container['remoteConfigAsFile'];
    }

    /**
     * Sets remoteConfigAsFile.
     *
     * @param null|\EdgeBox\SyncCore\V2\Raw\Model\DynamicReference $remoteConfigAsFile remoteConfigAsFile
     *
     * @return self
     */
    public function setRemoteConfigAsFile($remoteConfigAsFile)
    {
        $this->container['remoteConfigAsFile'] = $remoteConfigAsFile;

        return $this;
    }

    /**
     * Gets id.
     *
     * @return string
     */
    public function getId()
    {
        return $this->container['id'];
    }

    /**
     * Sets id.
     *
     * @param string $id id
     *
     * @return self
     */
    public function setId($id)
    {
        $this->container['id'] = $id;

        return $this;
    }

    /**
     * Gets createdAt.
     *
     * @return float
     */
    public function getCreatedAt()
    {
        return $this->container['createdAt'];
    }

    /**
     * Sets createdAt.
     *
     * @param float $createdAt createdAt
     *
     * @return self
     */
    public function setCreatedAt($createdAt)
    {
        $this->container['createdAt'] = $createdAt;

        return $this;
    }

    /**
     * Gets updatedAt.
     *
     * @return float
     */
    public function getUpdatedAt()
    {
        return $this->container['updatedAt'];
    }

    /**
     * Sets updatedAt.
     *
     * @param float $updatedAt updatedAt
     *
     * @return self
     */
    public function setUpdatedAt($updatedAt)
    {
        $this->container['updatedAt'] = $updatedAt;

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
