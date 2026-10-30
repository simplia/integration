<?php

namespace Simplia\Integration\Tests\Proxy;

use PHPUnit\Framework\TestCase;
use Simplia\Integration\Proxy\ProxyConfig;
use Simplia\Integration\Proxy\ProxyHttpClient;
use Simplia\Integration\Proxy\ProxySoapClient;
use Simplia\Integration\Proxy\ProxyTransportException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Runs against the local Envoy harness of the integration proxy in the
 * kubernetes repository: `scripts/integration-proxy/e2e/run.sh --keep` leaves
 * the stack up (proxy on localhost:18443) and writes the env this test needs
 * to e2e/out/env; `run.sh --down` stops it. With vendor/ installed:
 *
 *   docker run --rm --network host -v "$PWD:/app" -w /app -v <kubernetes>/scripts/integration-proxy/e2e/out:/e2e:ro \
 *       --entrypoint sh bref/php-84:2 -c 'set -a; . /e2e/env; set +a; php vendor/bin/phpunit tests/Proxy/ProxyIntegrationTest.php'
 *
 * Skipped unless INTEGRATION_PROXY_URL is set.
 */
class ProxyIntegrationTest extends TestCase {
    private const VARIABLES = [
        'INTEGRATION_PROXY_URL',
        'INTEGRATION_PROXY_CERT',
        'INTEGRATION_PROXY_KEY',
        'INTEGRATION_PROXY_CA',
        'INTEGRATION_PROXY_E2E_ALLOWED_URL',
        'INTEGRATION_PROXY_E2E_DENIED_URL',
        'INTEGRATION_PROXY_E2E_TARGET_CA',
    ];

    private array $env = [];
    private ?string $targetCaFile = null;

    protected function setUp(): void {
        if (getenv('INTEGRATION_PROXY_URL') === false) {
            self::markTestSkipped('INTEGRATION_PROXY_URL is not set (start the k8s e2e harness and load its out/env)');
        }
        foreach (self::VARIABLES as $name) {
            $value = getenv($name);
            if ($value === false || $value === '') {
                self::markTestSkipped("$name is not set (the e2e harness out/env provides it)");
            }
            $this->env[$name] = $value;
        }
        $this->targetCaFile = tempnam(sys_get_temp_dir(), 'target-ca');
        file_put_contents($this->targetCaFile, $this->env['INTEGRATION_PROXY_E2E_TARGET_CA']);
    }

    protected function tearDown(): void {
        if ($this->targetCaFile !== null) {
            unlink($this->targetCaFile);
        }
        unset($_SERVER['no_proxy']);
    }

    private function client(): HttpClientInterface {
        $config = ProxyConfig::fromLocalEnvironment($this->env);
        self::assertNotNull($config);

        return ProxyHttpClient::create($config);
    }

    /**
     * The way callers classify a failed request made with the plain client.
     */
    private function send(string $method, string $url, array $options = []): int {
        $response = $this->client()->request($method, $url, $options + ['timeout' => 5]);
        try {
            return $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw ProxyTransportException::fromTransport($e, $response->getInfo());
        }
    }

    private function targetPin(): string {
        $key = openssl_pkey_get_details(openssl_pkey_get_public($this->env['INTEGRATION_PROXY_E2E_TARGET_CA']))['key'];
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $key));

        return base64_encode(hash('sha256', $der, true));
    }

    public function testAllowedTargetThroughTheTunnel(): void {
        self::assertSame(200, $this->send('GET', $this->env['INTEGRATION_PROXY_E2E_ALLOWED_URL'], ['cafile' => $this->targetCaFile]));
    }

    public function testAllowedTargetPinnedByPublicKey(): void {
        // How timo-new-order reaches Vision: no chain check, pin-sha256 only.
        $options = ['verify_peer' => false, 'verify_host' => false, 'peer_fingerprint' => ['pin-sha256' => [$this->targetPin()]]];

        self::assertSame(200, $this->send('POST', $this->env['INTEGRATION_PROXY_E2E_ALLOWED_URL'], $options + ['body' => 'x']));
    }

    public function testPinMismatchIsNotSent(): void {
        $options = ['verify_peer' => false, 'verify_host' => false, 'peer_fingerprint' => ['pin-sha256' => [base64_encode(str_repeat("\0", 32))]], 'body' => str_repeat('x', 4096)];

        try {
            $this->send('POST', $this->env['INTEGRATION_PROXY_E2E_ALLOWED_URL'], $options);
            self::fail('Expected ProxyTransportException');
        } catch (ProxyTransportException $e) {
            self::assertFalse($e->mayHaveReachedTarget(), $e->getMessage());
            self::assertStringContainsString('pinned public key', $e->getMessage());
        }
    }

    /**
     * @requires extension soap
     */
    public function testSoapClientThroughTheProxy(): void {
        $wsdl = __DIR__ . '/../fixtures/echo.wsdl';
        $httpOptions = ['cafile' => $this->targetCaFile, 'timeout' => 5];

        // The e2e target answers "target-ok", not SOAP: a parse fault proves the round trip.
        $allowed = new ProxySoapClient($this->client(), $wsdl, ['location' => $this->env['INTEGRATION_PROXY_E2E_ALLOWED_URL']], $httpOptions);
        try {
            $allowed->echo('hello');
            self::fail('Expected SoapFault');
        } catch (\SoapFault $fault) {
            self::assertStringContainsString('no XML document', $fault->getMessage());
        }

        $denied = new ProxySoapClient($this->client(), $wsdl, ['location' => $this->env['INTEGRATION_PROXY_E2E_DENIED_URL']], $httpOptions);
        try {
            $denied->echo('hello');
            self::fail('Expected ProxyTransportException');
        } catch (ProxyTransportException $e) {
            self::assertFalse($e->mayHaveReachedTarget());
            self::assertStringContainsString('CONNECT tunnel failed, response 403', $e->getMessage());
        }
    }

    public function testDeniedTargetIsNotSent(): void {
        // no_proxy in the environment must not route around the proxy.
        $_SERVER['no_proxy'] = parse_url($this->env['INTEGRATION_PROXY_E2E_DENIED_URL'], PHP_URL_HOST);

        try {
            $this->send('POST', $this->env['INTEGRATION_PROXY_E2E_DENIED_URL'], ['cafile' => $this->targetCaFile, 'body' => str_repeat('x', 4096)]);
            self::fail('Expected ProxyTransportException');
        } catch (ProxyTransportException $e) {
            self::assertFalse($e->mayHaveReachedTarget());
            self::assertStringContainsString('CONNECT tunnel failed, response 403', $e->getMessage());
        }
    }
}
