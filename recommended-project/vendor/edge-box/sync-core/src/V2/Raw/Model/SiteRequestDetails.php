<?php
/**
 * SiteRequestDetails.
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
 * SiteRequestDetails Class Doc Comment.
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
class SiteRequestDetails implements ModelInterface, ArrayAccess, \JsonSerializable
{
    public const DISCRIMINATOR = null;

    /**
     * The original name of the model.
     *
     * @var string
     */
    protected static $openAPIModelName = 'SiteRequestDetails';

    /**
     * Array of property to type mappings. Used for (de)serialization.
     *
     * @var string[]
     */
    protected static $openAPITypes = [
        'requestUrl' => 'string',
        'requestBody' => 'string',
        'requestHeaders' => 'mixed',
        'requestMethod' => 'string',
        'requestMaxFollow' => 'float',
        'requestMaxSize' => 'float',
        'requestTimeout' => 'float',
        'requestStart' => 'float',
        'requestEnd' => 'float',
        'responseBody' => 'string',
        'responseHeaders' => 'mixed',
        'responseStatusCode' => 'float',
        'responseStatusText' => 'string',
    ];

    /**
     * Array of property to format mappings. Used for (de)serialization.
     *
     * @var string[]
     * @phpstan-var array<string, string|null>
     * @psalm-var array<string, string|null>
     */
    protected static $openAPIFormats = [
        'requestUrl' => null,
        'requestBody' => null,
        'requestHeaders' => null,
        'requestMethod' => null,
        'requestMaxFollow' => null,
        'requestMaxSize' => null,
        'requestTimeout' => null,
        'requestStart' => null,
        'requestEnd' => null,
        'responseBody' => null,
        'responseHeaders' => null,
        'responseStatusCode' => null,
        'responseStatusText' => null,
    ];

    /**
     * Array of attributes where the key is the local name,
     * and the value is the original name.
     *
     * @var string[]
     */
    protected static $attributeMap = [
        'requestUrl' => 'requestUrl',
        'requestBody' => 'requestBody',
        'requestHeaders' => 'requestHeaders',
        'requestMethod' => 'requestMethod',
        'requestMaxFollow' => 'requestMaxFollow',
        'requestMaxSize' => 'requestMaxSize',
        'requestTimeout' => 'requestTimeout',
        'requestStart' => 'requestStart',
        'requestEnd' => 'requestEnd',
        'responseBody' => 'responseBody',
        'responseHeaders' => 'responseHeaders',
        'responseStatusCode' => 'responseStatusCode',
        'responseStatusText' => 'responseStatusText',
    ];

    /**
     * Array of attributes to setter functions (for deserialization of responses).
     *
     * @var string[]
     */
    protected static $setters = [
        'requestUrl' => 'setRequestUrl',
        'requestBody' => 'setRequestBody',
        'requestHeaders' => 'setRequestHeaders',
        'requestMethod' => 'setRequestMethod',
        'requestMaxFollow' => 'setRequestMaxFollow',
        'requestMaxSize' => 'setRequestMaxSize',
        'requestTimeout' => 'setRequestTimeout',
        'requestStart' => 'setRequestStart',
        'requestEnd' => 'setRequestEnd',
        'responseBody' => 'setResponseBody',
        'responseHeaders' => 'setResponseHeaders',
        'responseStatusCode' => 'setResponseStatusCode',
        'responseStatusText' => 'setResponseStatusText',
    ];

    /**
     * Array of attributes to getter functions (for serialization of requests).
     *
     * @var string[]
     */
    protected static $getters = [
        'requestUrl' => 'getRequestUrl',
        'requestBody' => 'getRequestBody',
        'requestHeaders' => 'getRequestHeaders',
        'requestMethod' => 'getRequestMethod',
        'requestMaxFollow' => 'getRequestMaxFollow',
        'requestMaxSize' => 'getRequestMaxSize',
        'requestTimeout' => 'getRequestTimeout',
        'requestStart' => 'getRequestStart',
        'requestEnd' => 'getRequestEnd',
        'responseBody' => 'getResponseBody',
        'responseHeaders' => 'getResponseHeaders',
        'responseStatusCode' => 'getResponseStatusCode',
        'responseStatusText' => 'getResponseStatusText',
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
        $this->container['requestUrl'] = $data['requestUrl'] ?? null;
        $this->container['requestBody'] = $data['requestBody'] ?? null;
        $this->container['requestHeaders'] = $data['requestHeaders'] ?? null;
        $this->container['requestMethod'] = $data['requestMethod'] ?? null;
        $this->container['requestMaxFollow'] = $data['requestMaxFollow'] ?? null;
        $this->container['requestMaxSize'] = $data['requestMaxSize'] ?? null;
        $this->container['requestTimeout'] = $data['requestTimeout'] ?? null;
        $this->container['requestStart'] = $data['requestStart'] ?? null;
        $this->container['requestEnd'] = $data['requestEnd'] ?? null;
        $this->container['responseBody'] = $data['responseBody'] ?? null;
        $this->container['responseHeaders'] = $data['responseHeaders'] ?? null;
        $this->container['responseStatusCode'] = $data['responseStatusCode'] ?? null;
        $this->container['responseStatusText'] = $data['responseStatusText'] ?? null;
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
        return [];
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
     * Gets requestUrl.
     *
     * @return null|string
     */
    public function getRequestUrl()
    {
        return $this->container['requestUrl'];
    }

    /**
     * Sets requestUrl.
     *
     * @param null|string $requestUrl requestUrl
     *
     * @return self
     */
    public function setRequestUrl($requestUrl)
    {
        $this->container['requestUrl'] = $requestUrl;

        return $this;
    }

    /**
     * Gets requestBody.
     *
     * @return null|string
     */
    public function getRequestBody()
    {
        return $this->container['requestBody'];
    }

    /**
     * Sets requestBody.
     *
     * @param null|string $requestBody requestBody
     *
     * @return self
     */
    public function setRequestBody($requestBody)
    {
        $this->container['requestBody'] = $requestBody;

        return $this;
    }

    /**
     * Gets requestHeaders.
     *
     * @return null|mixed
     */
    public function getRequestHeaders()
    {
        return $this->container['requestHeaders'];
    }

    /**
     * Sets requestHeaders.
     *
     * @param null|mixed $requestHeaders requestHeaders
     *
     * @return self
     */
    public function setRequestHeaders($requestHeaders)
    {
        $this->container['requestHeaders'] = $requestHeaders;

        return $this;
    }

    /**
     * Gets requestMethod.
     *
     * @return null|string
     */
    public function getRequestMethod()
    {
        return $this->container['requestMethod'];
    }

    /**
     * Sets requestMethod.
     *
     * @param null|string $requestMethod requestMethod
     *
     * @return self
     */
    public function setRequestMethod($requestMethod)
    {
        $this->container['requestMethod'] = $requestMethod;

        return $this;
    }

    /**
     * Gets requestMaxFollow.
     *
     * @return null|float
     */
    public function getRequestMaxFollow()
    {
        return $this->container['requestMaxFollow'];
    }

    /**
     * Sets requestMaxFollow.
     *
     * @param null|float $requestMaxFollow requestMaxFollow
     *
     * @return self
     */
    public function setRequestMaxFollow($requestMaxFollow)
    {
        $this->container['requestMaxFollow'] = $requestMaxFollow;

        return $this;
    }

    /**
     * Gets requestMaxSize.
     *
     * @return null|float
     */
    public function getRequestMaxSize()
    {
        return $this->container['requestMaxSize'];
    }

    /**
     * Sets requestMaxSize.
     *
     * @param null|float $requestMaxSize requestMaxSize
     *
     * @return self
     */
    public function setRequestMaxSize($requestMaxSize)
    {
        $this->container['requestMaxSize'] = $requestMaxSize;

        return $this;
    }

    /**
     * Gets requestTimeout.
     *
     * @return null|float
     */
    public function getRequestTimeout()
    {
        return $this->container['requestTimeout'];
    }

    /**
     * Sets requestTimeout.
     *
     * @param null|float $requestTimeout requestTimeout
     *
     * @return self
     */
    public function setRequestTimeout($requestTimeout)
    {
        $this->container['requestTimeout'] = $requestTimeout;

        return $this;
    }

    /**
     * Gets requestStart.
     *
     * @return null|float
     */
    public function getRequestStart()
    {
        return $this->container['requestStart'];
    }

    /**
     * Sets requestStart.
     *
     * @param null|float $requestStart requestStart
     *
     * @return self
     */
    public function setRequestStart($requestStart)
    {
        $this->container['requestStart'] = $requestStart;

        return $this;
    }

    /**
     * Gets requestEnd.
     *
     * @return null|float
     */
    public function getRequestEnd()
    {
        return $this->container['requestEnd'];
    }

    /**
     * Sets requestEnd.
     *
     * @param null|float $requestEnd requestEnd
     *
     * @return self
     */
    public function setRequestEnd($requestEnd)
    {
        $this->container['requestEnd'] = $requestEnd;

        return $this;
    }

    /**
     * Gets responseBody.
     *
     * @return null|string
     */
    public function getResponseBody()
    {
        return $this->container['responseBody'];
    }

    /**
     * Sets responseBody.
     *
     * @param null|string $responseBody responseBody
     *
     * @return self
     */
    public function setResponseBody($responseBody)
    {
        $this->container['responseBody'] = $responseBody;

        return $this;
    }

    /**
     * Gets responseHeaders.
     *
     * @return null|mixed
     */
    public function getResponseHeaders()
    {
        return $this->container['responseHeaders'];
    }

    /**
     * Sets responseHeaders.
     *
     * @param null|mixed $responseHeaders responseHeaders
     *
     * @return self
     */
    public function setResponseHeaders($responseHeaders)
    {
        $this->container['responseHeaders'] = $responseHeaders;

        return $this;
    }

    /**
     * Gets responseStatusCode.
     *
     * @return null|float
     */
    public function getResponseStatusCode()
    {
        return $this->container['responseStatusCode'];
    }

    /**
     * Sets responseStatusCode.
     *
     * @param null|float $responseStatusCode responseStatusCode
     *
     * @return self
     */
    public function setResponseStatusCode($responseStatusCode)
    {
        $this->container['responseStatusCode'] = $responseStatusCode;

        return $this;
    }

    /**
     * Gets responseStatusText.
     *
     * @return null|string
     */
    public function getResponseStatusText()
    {
        return $this->container['responseStatusText'];
    }

    /**
     * Sets responseStatusText.
     *
     * @param null|string $responseStatusText responseStatusText
     *
     * @return self
     */
    public function setResponseStatusText($responseStatusText)
    {
        $this->container['responseStatusText'] = $responseStatusText;

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
