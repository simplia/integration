<?php

namespace Simplia\Integration\Tests\Proxy;

use PHPUnit\Framework\TestCase;
use Simplia\Integration\Proxy\ProxyConfig;
use Simplia\Integration\Proxy\ProxyConfigurationException;

class ProxyConfigTest extends TestCase {
    private const URL = 'https://integration-proxy.simplia.cz:8443';
    private const PATH = '/integration-proxy/client/timo-erp-stock--timo';
    private const CERT = "-----BEGIN CERTIFICATE-----\nMIIBcert\n-----END CERTIFICATE-----\n";
    private const KEY = "-----BEGIN PRIVATE KEY-----\nMIGHkey\n-----END PRIVATE KEY-----\n";
    private const CA = "-----BEGIN CERTIFICATE-----\nMIIBca\n-----END CERTIFICATE-----\n";

    private static function json(array $override = [], array $remove = []): string {
        $values = array_merge(['cert' => self::CERT, 'key' => self::KEY, 'ca' => self::CA], $override);
        foreach ($remove as $name) {
            unset($values[$name]);
        }

        return json_encode($values);
    }

    public function testValidParameterJson(): void {
        $config = ProxyConfig::fromParameterJson(self::URL, self::json(), self::PATH);

        self::assertSame(self::URL, $config->url);
        self::assertSame(self::CERT, $config->cert);
        self::assertSame(self::KEY, $config->key);
        self::assertSame(self::CA, $config->ca);
    }

    /**
     * @dataProvider invalidParameterProvider
     */
    public function testInvalidParameterNamesThePath(string $url, string $json): void {
        try {
            ProxyConfig::fromParameterJson($url, $json, self::PATH);
            self::fail('Expected ProxyConfigurationException');
        } catch (ProxyConfigurationException $e) {
            self::assertStringContainsString(self::PATH, $e->getMessage());
            self::assertStringNotContainsString('MIGHkey', $e->getMessage(), 'the message must not leak the key');
        }
    }

    public static function invalidParameterProvider(): array {
        return [
            'missing key' => [self::URL, self::json([], ['key'])],
            'empty ca' => [self::URL, self::json(['ca' => ''])],
            'cert not PEM' => [self::URL, self::json(['cert' => 'abc'])],
            'key not a string' => [self::URL, self::json(['key' => ['x']])],
            'invalid JSON' => [self::URL, '{"cert": '],
            'JSON not an object' => [self::URL, '"x"'],
            'http url' => ['http://integration-proxy.simplia.cz:8443', self::json()],
            'empty url' => ['', self::json()],
        ];
    }

    public function testLocalEnvironmentWithoutUrlIsNull(): void {
        self::assertNull(ProxyConfig::fromLocalEnvironment([]));
    }

    public function testLocalEnvironment(): void {
        $config = ProxyConfig::fromLocalEnvironment([
            'INTEGRATION_PROXY_URL' => self::URL,
            'INTEGRATION_PROXY_CERT' => self::CERT,
            'INTEGRATION_PROXY_KEY' => self::KEY,
            'INTEGRATION_PROXY_CA' => self::CA,
        ]);

        self::assertNotNull($config);
        self::assertSame(self::URL, $config->url);
        self::assertSame(self::KEY, $config->key);
    }

    public function testLocalEnvironmentAcceptsOneLinePemsWithLiteralNewlines(): void {
        $oneLine = fn(string $pem) => str_replace("\n", '\n', $pem);

        $config = ProxyConfig::fromLocalEnvironment([
            'INTEGRATION_PROXY_URL' => self::URL,
            'INTEGRATION_PROXY_CERT' => $oneLine(self::CERT),
            'INTEGRATION_PROXY_KEY' => $oneLine(self::KEY),
            'INTEGRATION_PROXY_CA' => $oneLine(self::CA),
        ]);

        self::assertNotNull($config);
        self::assertSame(self::CERT, $config->cert);
        self::assertSame(self::KEY, $config->key);
        self::assertSame(self::CA, $config->ca);
    }

    public function testLocalEnvironmentWithUrlButMissingKeyThrows(): void {
        $this->expectException(ProxyConfigurationException::class);
        $this->expectExceptionMessage('INTEGRATION_PROXY_KEY');

        ProxyConfig::fromLocalEnvironment([
            'INTEGRATION_PROXY_URL' => self::URL,
            'INTEGRATION_PROXY_CERT' => self::CERT,
            'INTEGRATION_PROXY_CA' => self::CA,
        ]);
    }

    public function testLocalEnvironmentWithEmptyUrlThrows(): void {
        $this->expectException(ProxyConfigurationException::class);
        $this->expectExceptionMessage('INTEGRATION_PROXY_URL');

        ProxyConfig::fromLocalEnvironment([
            'INTEGRATION_PROXY_URL' => '',
            'INTEGRATION_PROXY_CERT' => self::CERT,
            'INTEGRATION_PROXY_KEY' => self::KEY,
            'INTEGRATION_PROXY_CA' => self::CA,
        ]);
    }

    public function testDebugOutputHidesTheKey(): void {
        $config = ProxyConfig::fromParameterJson(self::URL, self::json(), self::PATH);

        self::assertStringNotContainsString('MIGHkey', print_r($config, true));
        self::assertStringContainsString(self::URL, print_r($config, true));
    }
}
