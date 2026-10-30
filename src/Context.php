<?php

namespace Simplia\Integration;

use Simplia\Api\Api;
use Simplia\Integration\Event\IntegrationEvent;
use Simplia\Integration\Proxy\ProxyConfigurationException;
use Simplia\Integration\Storage\FileStorage;
use Simplia\Integration\Storage\KeyValueStorage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Context {
    private HttpClientInterface $client;
    private Api $api;
    private KeyValueStorage $keyValueStorage;
    private FileStorage $fileStorage;
    private LockHandler $lockHandler;
    private array $parameters;
    private ?IntegrationEvent $event;
    private ?HttpClientInterface $proxyClient;

    public function __construct(HttpClientInterface $client, Api $api, KeyValueStorage $keyValueStorage, FileStorage $fileStorage, array $parameters, ?IntegrationEvent $event, ?HttpClientInterface $proxyClient = null) {
        $this->client = $client;
        $this->api = $api;
        $this->keyValueStorage = $keyValueStorage;
        $this->fileStorage = $fileStorage;
        $this->lockHandler = new LockHandler($keyValueStorage);
        $this->parameters = $parameters;
        $this->event = $event;
        $this->proxyClient = $proxyClient;
    }

    public function getClient(): HttpClientInterface {
        return $this->client;
    }

    /**
     * HTTP client tunnelling through the integration proxy (fixed egress IPs for third parties with an allowlist).
     *
     * @throws ProxyConfigurationException when the instance is not opted in
     */
    public function getProxyClient(): HttpClientInterface {
        if ($this->proxyClient === null) {
            throw new ProxyConfigurationException('Integration proxy is not enabled for this instance (INTEGRATION_PROXY_PARAMETER_PATH is not set)');
        }

        return $this->proxyClient;
    }

    public function getApi(): Api {
        return $this->api;
    }

    public function getKeyValueStorage(): KeyValueStorage {
        return $this->keyValueStorage;
    }

    public function getFileStorage(): FileStorage {
        return $this->fileStorage;
    }

    public function getParameters(): array {
        return $this->parameters;
    }

    public function getEvent(): ?IntegrationEvent {
        return $this->event;
    }

    public function getLockHandler(): LockHandler {
        return $this->lockHandler;
    }
}
