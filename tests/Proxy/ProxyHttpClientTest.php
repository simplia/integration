<?php

namespace Simplia\Integration\Tests\Proxy;

use PHPUnit\Framework\TestCase;
use Simplia\Integration\Proxy\ProxyConfig;
use Simplia\Integration\Proxy\ProxyHttpClient;
use Symfony\Component\HttpClient\CurlHttpClient;

/**
 * Pins the options that keep every request inside the mTLS tunnel. Read from
 * the client's default options (no network); the live behaviour is covered by
 * ProxyIntegrationTest.
 */
class ProxyHttpClientTest extends TestCase {
    private const URL = 'https://integration-proxy.simplia.cz:8443';
    private const CERT = "-----BEGIN CERTIFICATE-----\nMIIBcert\n-----END CERTIFICATE-----\n";
    private const KEY = "-----BEGIN PRIVATE KEY-----\nMIGHkey\n-----END PRIVATE KEY-----\n";
    private const CA = "-----BEGIN CERTIFICATE-----\nMIIBca\n-----END CERTIFICATE-----\n";

    public function testSafetyOptions(): void {
        $config = ProxyConfig::fromParameterJson(self::URL, json_encode(['cert' => self::CERT, 'key' => self::KEY, 'ca' => self::CA]), '/integration-proxy/client/a--b');

        $client = ProxyHttpClient::create($config);

        self::assertInstanceOf(CurlHttpClient::class, $client);
        $options = (new \ReflectionProperty(CurlHttpClient::class, 'defaultOptions'))->getValue($client);
        self::assertSame(self::URL, $options['proxy']);
        self::assertSame('', $options['no_proxy'], 'no_proxy must not be null, or NO_PROXY from the environment applies');
        $curl = $options['extra']['curl'];
        self::assertTrue($curl[CURLOPT_HTTPPROXYTUNNEL]);
        self::assertTrue($curl[CURLOPT_SUPPRESS_CONNECT_HEADERS]);
        self::assertSame(self::CERT, $curl[CURLOPT_PROXY_SSLCERT_BLOB]);
        self::assertSame(self::KEY, $curl[CURLOPT_PROXY_SSLKEY_BLOB]);
        self::assertSame(self::CA, $curl[CURLOPT_PROXY_CAINFO_BLOB]);
        self::assertArrayNotHasKey(CURLOPT_PROXY, $curl, 'the proxy URL goes through Symfony\'s proxy option');
    }
}
