<?php

namespace Drupal\cms_content_sync;

/**
 * Class SyncCoreBatchCollection.
 *
 * Collect a number of requests to be executed as a batch operation.
 */
class SyncCoreBatchCollection
{
    protected $operations = [];

    /**
     * Get an existing item by ID.
     *
     * @param $id
     * @param $type
     */
    public function get($id, $type)
    {
        foreach ($this->operations as $operation) {
            if ($operation['type'] !== $type) {
                continue;
            }

            if ($operation['item']['id'] !== $id) {
                continue;
            }

            return $operation['item'];
        }

        return null;
    }

    /**
     * Add or overwrite an item if one exists with the given ID.
     *
     * @param array  $item
     * @param string $type
     *
     * @return $this
     */
    public function add($item, $type)
    {
        // If an item with that ID already exists, we overwrite it.
        foreach ($this->operations as &$operation) {
            if ($operation['type'] !== $type) {
                continue;
            }

            if ($operation['item']['id'] !== $item['id']) {
                continue;
            }

            $operation['item'] = $item;

            return $this;
        }

        $this->operations[] = [
            'type' => $type,
            'item' => $item,
        ];

        return $this;
    }

    /**
     * @param array $items
     * @param bool  $prepend
     */
    public function addMultiple($items, $prepend = false)
    {
        $this->operations = $prepend ? array_merge($items, $this->operations) : array_merge($this->operations, $items);
    }

    /**
     * @return array
     */
    public function getOperations()
    {
        return $this->operations;
    }
}
