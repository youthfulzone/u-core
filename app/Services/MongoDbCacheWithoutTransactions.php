<?php

namespace App\Services;

use MongoDB\Client;
use MongoDB\BSON\UTCDateTime;

class MongoDbCacheWithoutTransactions
{
    private static $client = null;
    private static $collection = null;

    /**
     * Get MongoDB collection directly
     */
    private static function getCollection()
    {
        if (self::$collection === null) {
            self::$client = new Client("mongodb://127.0.0.1:27017");
            self::$collection = self::$client->selectDatabase('admin_database')->selectCollection('cache');
        }
        return self::$collection;
    }

    /**
     * Increment a cache value without using transactions
     */
    public static function increment($key, $value = 1)
    {
        $collection = self::getCollection();

        // Use atomic findOneAndUpdate to increment without transactions
        $result = $collection->findOneAndUpdate(
            ['key' => $key],
            [
                '$inc' => ['value' => $value],
                '$set' => ['expiration' => new UTCDateTime((time() + 3600) * 1000)]
            ],
            [
                'upsert' => true,
                'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
            ]
        );

        return $result ? (int)($result->value ?? 1) : 1;
    }

    /**
     * Get a cache value
     */
    public static function get($key, $default = null)
    {
        $collection = self::getCollection();

        // Find item that hasn't expired
        $item = $collection->findOne([
            'key' => $key,
            'expiration' => ['$gt' => new UTCDateTime(time() * 1000)]
        ]);

        return $item ? ($item->value ?? $default) : $default;
    }

    /**
     * Set a cache value
     */
    public static function put($key, $value, $seconds = 3600)
    {
        $collection = self::getCollection();

        // Replace or insert the cache item
        $collection->replaceOne(
            ['key' => $key],
            [
                'key' => $key,
                'value' => $value,
                'expiration' => new UTCDateTime((time() + $seconds) * 1000)
            ],
            ['upsert' => true]
        );

        return true;
    }
}
