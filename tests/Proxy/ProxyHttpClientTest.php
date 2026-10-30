<?php

namespace Simplia\Integration\Tests\Proxy;

use PHPUnit\Framework\TestCase;
use Simplia\Integration\Proxy\GuardedProxyHttpClient;
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

        self::assertInstanceOf(GuardedProxyHttpClient::class, $client);
        $inner = (new \ReflectionProperty(GuardedProxyHttpClient::class, 'inner'))->getValue($client);
        self::assertInstanceOf(CurlHttpClient::class, $inner);
        $options = (new \ReflectionProperty(CurlHttpClient::class, 'defaultOptions'))->getValue($inner);
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

    public static function reservedOptions(): array {
        return [
            'proxy null' => ['proxy', null],
            'proxy elsewhere' => ['proxy', 'http://other.example:3128'],
            'no_proxy' => ['no_proxy', '*'],
            'extra' => ['extra', ['curl' => [CURLOPT_VERBOSE => true]]],
        ];
    }

    /**
     * @dataProvider reservedOptions
     */
    public function testRequestRejectsReservedOptions(string $name, mixed $value): void {
        $client = $this->client();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("\"$name\"");
        $client->request('GET', 'https://target.example/', [$name => $value]);
    }

    /**
     * @dataProvider reservedOptions
     */
    public function testWithOptionsRejectsReservedOptions(string $name, mixed $value): void {
        $client = $this->client();

        $this->expectException(\InvalidArgumentException::class);
        $client->withOptions([$name => $value]);
    }

    public function testWithOptionsKeepsTheGuard(): void {
        $clone = $this->client()->withOptions(['timeout' => 3]);

        self::assertInstanceOf(GuardedProxyHttpClient::class, $clone);
        $this->expectException(\InvalidArgumentException::class);
        $clone->request('GET', 'https://target.example/', ['proxy' => null]);
    }

    public function testTraceableWrapperStillRejects(): void {
        $client = new \Symfony\Component\HttpClient\TraceableHttpClient($this->client());

        $this->expectException(\InvalidArgumentException::class);
        $client->request('GET', 'https://target.example/', ['no_proxy' => '*']);
    }

    private function client(): GuardedProxyHttpClient {
        $config = ProxyConfig::fromParameterJson(self::URL, json_encode(['cert' => self::CERT, 'key' => self::KEY, 'ca' => self::CA]), '/integration-proxy/client/a--b');
        $client = ProxyHttpClient::create($config);
        self::assertInstanceOf(GuardedProxyHttpClient::class, $client);

        return $client;
    }
}
