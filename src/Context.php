<?php

namespace Simplia\Integration;

use Simplia\Api\Api;
use Simplia\Integration\Event\IntegrationEvent;
use Simplia\Integration\Storage\KeyValueStorage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Context {
    private HttpClientInterface $client;
    private Api $api;
    private KeyValueStorage $keyValueStorage;
    private array $parameters;
    private ?IntegrationEvent $event;

    public function __construct(HttpClientInterface $client, Api $api, KeyValueStorage $keyValueStorage, array $parameters, ?IntegrationEvent $event) {
        $this->client = $client;
        $this->api = $api;
        $this->keyValueStorage = $keyValueStorage;
        $this->parameters = $parameters;
        $this->event = $event;
    }

    public function getClient(): HttpClientInterface {
        return $this->client;
    }

    public function getApi(): Api {
        return $this->api;
    }

    public function getKeyValueStorage(): KeyValueStorage {
        return $this->keyValueStorage;
    }

    public function getParameters(): array {
        return $this->parameters;
    }

    public function getEvent(): ?IntegrationEvent {
        return $this->event;
    }
}
