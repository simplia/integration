<?php

namespace Simplia\Integration\Tests;

use PHPUnit\Framework\TestCase;
use Simplia\Api\Api;
use Simplia\Integration\Context;
use Simplia\Integration\Handler;
use Simplia\Integration\Proxy\ProxyConfigurationException;
use Simplia\Integration\Storage\FileStorage;
use Simplia\Integration\Storage\KeyValueStorage;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;

class HandlerProxyConfigTest extends TestCase {
    private const PATH = '/integration-proxy/client/repo--shop';
    private const URL = 'https://integration-proxy.simplia.cz:8443';
    private const KEY = "-----BEGIN PRIVATE KEY-----\nSECRETKEYBODY\n-----END PRIVATE KEY-----\n";

    private static function json(): string {
        return json_encode([
            'cert' => "-----BEGIN CERTIFICATE-----\nAAA\n-----END CERTIFICATE-----\n",
            'key' => self::KEY,
            'ca' => "-----BEGIN CERTIFICATE-----\nBBB\n-----END CERTIFICATE-----\n",
        ]);
    }

    public function testNotEnabled(): void {
        self::assertNull(Handler::proxyConfigFromParameters([], []));
        self::assertNull(Handler::proxyConfigFromParameters(['INTEGRATION_PROXY_URL' => self::URL], []));
    }

    public function testConfigFromParameter(): void {
        $config = Handler::proxyConfigFromParameters(
            ['INTEGRATION_PROXY_PARAMETER_PATH' => self::PATH, 'INTEGRATION_PROXY_URL' => self::URL],
            [self::PATH => self::json(), '/other' => 'x']
        );

        self::assertNotNull($config);
        self::assertSame(self::URL, $config->url);
        self::assertSame(self::KEY, $config->key);
    }

    public function testMissingParameterNamesThePath(): void {
        try {
            Handler::proxyConfigFromParameters(['INTEGRATION_PROXY_PARAMETER_PATH' => self::PATH, 'INTEGRATION_PROXY_URL' => self::URL], ['/other' => 'x']);
            self::fail('Expected ProxyConfigurationException');
        } catch (ProxyConfigurationException $e) {
            self::assertStringContainsString(self::PATH, $e->getMessage());
        }
    }

    public function testMissingUrl(): void {
        $this->expectException(ProxyConfigurationException::class);
        $this->expectExceptionMessage('INTEGRATION_PROXY_URL');
        Handler::proxyConfigFromParameters(['INTEGRATION_PROXY_PARAMETER_PATH' => self::PATH], [self::PATH => self::json()]);
    }

    public function testInvalidValueNeverLeaksTheKey(): void {
        try {
            Handler::proxyConfigFromParameters(['INTEGRATION_PROXY_PARAMETER_PATH' => self::PATH, 'INTEGRATION_PROXY_URL' => self::URL], [self::PATH => '{"cert":"x","key":"' . 'SECRETKEYBODY' . '"}']);
            self::fail('Expected ProxyConfigurationException');
        } catch (ProxyConfigurationException $e) {
            self::assertStringNotContainsString('SECRETKEYBODY', $e->getMessage());
        }
    }

    public function testContextWithoutProxy(): void {
        $context = new Context(new MockHttpClient(), Api::withJWT(new Psr18Client(new MockHttpClient()), 'shop.example', 'jwt'), $this->createMock(KeyValueStorage::class), $this->createMock(FileStorage::class), [], null);

        $this->expectException(ProxyConfigurationException::class);
        $this->expectExceptionMessage('Integration proxy is not enabled for this instance (INTEGRATION_PROXY_PARAMETER_PATH is not set)');
        $context->getProxyClient();
    }

    public function testContextWithProxy(): void {
        $proxy = new MockHttpClient();
        $context = new Context(new MockHttpClient(), Api::withJWT(new Psr18Client(new MockHttpClient()), 'shop.example', 'jwt'), $this->createMock(KeyValueStorage::class), $this->createMock(FileStorage::class), [], null, $proxy);

        self::assertSame($proxy, $context->getProxyClient());
    }
}
