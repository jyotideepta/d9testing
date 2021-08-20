<?php
/**
 * RemoteEntityTypeVersionEntity.
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
 * RemoteEntityTypeVersionEntity Class Doc Comment.
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
class RemoteEntityTypeVersionEntity implements ModelInterface, ArrayAccess, \JsonSerializable
{
    public const DISCRIMINATOR = null;

    /**
     * The original name of the model.
     *
     * @var string
     */
    protected static $openAPIModelName = 'RemoteEntityTypeVersionEntity';

    /**
     * Array of property to type mappings. Used for (de)serialization.
     *
     * @var string[]
     */
    protected static $openAPITypes = [
        'appType' => '\EdgeBox\SyncCore\V2\Raw\Model\SiteApplicationType',
        'name' => 'string',
        'namespaceMachineName' => 'string',
        'machineName' => 'string',
        'versionId' => 'string',
        'translatable' => 'bool',
        'properties' => '\EdgeBox\SyncCore\V2\Raw\Model\RemoteEntityTypeProperty[]',
        'customer' => '\EdgeBox\SyncCore\V2\Raw\Model\DynamicReference',
        'entityType' => '\EdgeBox\SyncCore\V2\Raw\Model\DynamicReference',
        'project' => '\EdgeBox\SyncCore\V2\Raw\Model\DynamicReference',
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
        'appType' => null,
        'name' => null,
        'namespaceMachineName' => null,
        'machineName' => null,
        'versionId' => null,
        'translatable' => null,
        'properties' => null,
        'customer' => null,
        'entityType' => null,
        'project' => null,
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
        'appType' => 'appType',
        'name' => 'name',
        'namespaceMachineName' => 'namespaceMachineName',
        'machineName' => 'machineName',
        'versionId' => 'versionId',
        'translatable' => 'translatable',
        'properties' => 'properties',
        'customer' => 'customer',
        'entityType' => 'entityType',
        'project' => 'project',
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
        'appType' => 'setAppType',
        'name' => 'setName',
        'namespaceMachineName' => 'setNamespaceMachineName',
        'machineName' => 'setMachineName',
        'versionId' => 'setVersionId',
        'translatable' => 'setTranslatable',
        'properties' => 'setProperties',
        'customer' => 'setCustomer',
        'entityType' => 'setEntityType',
        'project' => 'setProject',
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
        'appType' => 'getAppType',
        'name' => 'getName',
        'namespaceMachineName' => 'getNamespaceMachineName',
        'machineName' => 'getMachineName',
        'versionId' => 'getVersionId',
        'translatable' => 'getTranslatable',
        'properties' => 'getProperties',
        'customer' => 'getCustomer',
        'entityType' => 'getEntityType',
        'project' => 'getProject',
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
        $this->container['appType'] = $data['appType'] ?? null;
        $this->container['name'] = $data['name'] ?? null;
        $this->container['namespaceMachineName'] = $data['namespaceMachineName'] ?? null;
        $this->container['machineName'] = $data['machineName'] ?? null;
        $this->container['versionId'] = $data['versionId'] ?? null;
        $this->container['translatable'] = $data['translatable'] ?? null;
        $this->container['properties'] = $data['properties'] ?? null;
        $this->container['customer'] = $data['customer'] ?? null;
        $this->container['entityType'] = $data['entityType'] ?? null;
        $this->container['project'] = $data['project'] ?? null;
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

        if (null === $this->container['appType']) {
            $invalidProperties[] = "'appType' can't be null";
        }
        if (null === $this->container['name']) {
            $invalidProperties[] = "'name' can't be null";
        }
        if (null === $this->container['namespaceMachineName']) {
            $invalidProperties[] = "'namespaceMachineName' can't be null";
        }
        if (null === $this->container['machineName']) {
            $invalidProperties[] = "'machineName' can't be null";
        }
        if (null === $this->container['versionId']) {
            $invalidProperties[] = "'versionId' can't be null";
        }
        if (null === $this->container['properties']) {
            $invalidProperties[] = "'properties' can't be null";
        }
        if (null === $this->container['customer']) {
            $invalidProperties[] = "'customer' can't be null";
        }
        if (null === $this->container['entityType']) {
            $invalidProperties[] = "'entityType' can't be null";
        }
        if (null === $this->container['project']) {
            $invalidProperties[] = "'project' can't be null";
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
     * Gets appType.
     *
     * @return \EdgeBox\SyncCore\V2\Raw\Model\SiteApplicationType
     */
    public function getAppType()
    {
        return $this->container['appType'];
    }

    /**
     * Sets appType.
     *
     * @param \EdgeBox\SyncCore\V2\Raw\Model\SiteApplicationType $appType appType
     *
     * @return self
     */
    public function setAppType($appType)
    {
        $this->container['appType'] = $appType;

        return $this;
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
     * Gets namespaceMachineName.
     *
     * @return string
     */
    public function getNamespaceMachineName()
    {
        return $this->container['namespaceMachineName'];
    }

    /**
     * Sets namespaceMachineName.
     *
     * @param string $namespaceMachineName namespaceMachineName
     *
     * @return self
     */
    public function setNamespaceMachineName($namespaceMachineName)
    {
        $this->container['namespaceMachineName'] = $namespaceMachineName;

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
     * Gets versionId.
     *
     * @return string
     */
    public function getVersionId()
    {
        return $this->container['versionId'];
    }

    /**
     * Sets versionId.
     *
     * @param string $versionId versionId
     *
     * @return self
     */
    public function setVersionId($versionId)
    {
        $this->container['versionId'] = $versionId;

        return $this;
    }

    /**
     * Gets translatable.
     *
     * @return null|bool
     */
    public function getTranslatable()
    {
        return $this->container['translatable'];
    }

    /**
     * Sets translatable.
     *
     * @param null|bool $translatable translatable
     *
     * @return self
     */
    public function setTranslatable($translatable)
    {
        $this->container['translatable'] = $translatable;

        return $this;
    }

    /**
     * Gets properties.
     *
     * @return \EdgeBox\SyncCore\V2\Raw\Model\RemoteEntityTypeProperty[]
     */
    public function getProperties()
    {
        return $this->container['properties'];
    }

    /**
     * Sets properties.
     *
     * @param \EdgeBox\SyncCore\V2\Raw\Model\RemoteEntityTypeProperty[] $properties properties
     *
     * @return self
     */
    public function setProperties($properties)
    {
        $this->container['properties'] = $properties;

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
     * Gets entityType.
     *
     * @return \EdgeBox\SyncCore\V2\Raw\Model\DynamicReference
     */
    public function getEntityType()
    {
        return $this->container['entityType'];
    }

    /**
     * Sets entityType.
     *
     * @param \EdgeBox\SyncCore\V2\Raw\Model\DynamicReference $entityType entityType
     *
     * @return self
     */
    public function setEntityType($entityType)
    {
        $this->container['entityType'] = $entityType;

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