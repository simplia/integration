<?php

namespace Simplia\Integration\Storage;

use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Exception\ResourceNotFoundException;
use AsyncAws\DynamoDb\Input\GetItemInput;
use AsyncAws\DynamoDb\Input\PutItemInput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

class RemoteKeyValueStorage implements KeyValueStorage {
    private DynamoDbClient $client;
    private string $table;
    private string $namespace;

    public function __construct(DynamoDbClient $client, string $table, string $namespace) {
        $this->client = $client;
        $this->table = $table;
        $this->namespace = $namespace;
    }

    public function get(string $key) {
        $result = $this->client->getItem(new GetItemInput([
            'TableName' => $this->table,
            'ConsistentRead' => true,
            'Key' => [
                'PK' => new AttributeValue(['S' => $this->namespace]),
                'SK' => new AttributeValue(['S' => $key]),
            ],
        ]));

        if (!$result->getItem()) {
            return null;
        }

        return json_decode($result->getItem()['value']->getS(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function set(string $key, $value): void {
        $this->client->putItem(new PutItemInput([
            'TableName' => $this->table,
            'Item' => [
                'PK' => new AttributeValue(['S' => $this->namespace]),
                'SK' => new AttributeValue(['S' => $key]),
                'value' => new AttributeValue(['S' => json_encode($value, JSON_THROW_ON_ERROR)]),
                'date' => new AttributeValue(['S' => date('Y-m-d H:i:s')]),
            ],
        ]));
    }
}
