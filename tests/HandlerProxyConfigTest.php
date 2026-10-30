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

    public function testParameterNames(): void {
        $env = ['CREDENTIALS_PARAMETER_PATH' => '/c', 'SHOP_CREDENTIALS_PARAMETER_PATH' => '/s'];
        self::assertSame(['/c', '/s'], Handler::parameterNames($env));
        self::assertSame(['/c', '/s'], Handler::parameterNames($env + ['INTEGRATION_PROXY_PARAMETER_PATH' => '']));
        self::assertSame(['/c', '/s', self::PATH], Handler::parameterNames($env + ['INTEGRATION_PROXY_PARAMETER_PATH' => self::PATH]));
    }

    public function testSegmentDataSkipsRejectedRequests(): void {
        $traced = [
            ['method' => 'GET', 'url' => 'https://t.example/a', 'info' => []],
            ['method' => 'GET', 'url' => 'https://t.example/b', 'info' => ['http_code' => 200, 'start_time' => 10.5, 'total_time' => 0.5]],
        ];

        $data = Handler::segmentData($traced, true, 'https://shop.example/api/3/');

        self::assertCount(1, $data);
        self::assertSame(['url' => 'https://t.example/b', 'method' => 'GET', 'name' => 't.example', 'code' => 200, 'start' => 10.5, 'end' => 11.0], $data[0]);
    }

    public function testSegmentDataStripsQueryAndUserinfoOfProxiedUrls(): void {
        $traced = [['method' => 'POST', 'url' => 'https://user:pw@t.example:8443/p/q?key=SECRET#frag', 'info' => ['http_code' => 200, 'start_time' => 1.0, 'total_time' => 1.0]]];

        $data = Handler::segmentData($traced, true, 'https://shop.example/api/3/');

        self::assertSame('https://t.example:8443/p/q', $data[0]['url']);
        self::assertSame('t.example', $data[0]['name']);
    }

    public function testSegmentDataKeepsPlainUrlsExceptPresigned(): void {
        $info = ['http_code' => 200, 'start_time' => 1.0, 'total_time' => 1.0];
        $traced = [
            ['method' => 'GET', 'url' => 'https://shop.example/api/3/x?a=1', 'info' => $info],
            ['method' => 'GET', 'url' => 'https://s3.example/o?X-Amz-Signature=abc', 'info' => $info],
        ];

        $data = Handler::segmentData($traced, false, 'https://shop.example/api/3/');

        self::assertSame('https://shop.example/api/3/x?a=1', $data[0]['url']);
        self::assertSame('API shop.example', $data[0]['name']);
        self::assertSame('https://s3.example/o', $data[1]['url']);
    }
}
