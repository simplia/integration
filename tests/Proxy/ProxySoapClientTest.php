<?php

namespace Simplia\Integration\Tests\Proxy;

use PHPUnit\Framework\TestCase;
use Simplia\Integration\Proxy\ProxySoapClient;
use Simplia\Integration\Proxy\ProxyTransportException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @requires extension soap
 */
class ProxySoapClientTest extends TestCase {
    private const WSDL = __DIR__ . '/../fixtures/echo.wsdl';
    private const LOCATION = 'https://echo.example/soap';

    private const RESPONSE_11 = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="urn:Echo">'
        . '<SOAP-ENV:Body><ns1:echoResponse><return>hello back</return></ns1:echoResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>';

    private const RESPONSE_12 = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope" xmlns:ns1="urn:Echo">'
        . '<env:Body><ns1:echoResponse><return>hello back</return></ns1:echoResponse></env:Body></env:Envelope>';

    private const FAULT_11 = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
        . '<SOAP-ENV:Body><SOAP-ENV:Fault><faultcode>SOAP-ENV:Client.Validation</faultcode>'
        . '<faultstring>Unknown order</faultstring></SOAP-ENV:Fault></SOAP-ENV:Body></SOAP-ENV:Envelope>';

    /** @var list<array{string, string, array}> */
    private array $requests = [];

    /**
     * @param MockResponse|list<MockResponse> $responses
     */
    private function http($responses): MockHttpClient {
        $responses = is_array($responses) ? $responses : [$responses];

        return new MockHttpClient(function (string $method, string $url, array $options) use (&$responses) {
            $this->requests[] = [$method, $url, $options];

            return array_shift($responses);
        });
    }

    private static function header(array $options, string $name): ?string {
        $values = $options['normalized_headers'][strtolower($name)] ?? [];
        self::assertLessThanOrEqual(1, count($values), "header $name sent more than once");

        return $values === [] ? null : substr($values[0], strlen($name) + 2);
    }

    public function testSoap11Request(): void {
        $client = new ProxySoapClient(
            $this->http(new MockResponse(self::RESPONSE_11, ['response_headers' => ['Content-Type: text/xml; charset=utf-8']])),
            self::WSDL,
            ['login' => 'vision', 'password' => 's3cret'],
        );

        self::assertSame('hello back', $client->echo('hello'));

        self::assertCount(1, $this->requests);
        [$method, $url, $options] = $this->requests[0];
        self::assertSame('POST', $method);
        self::assertSame(self::LOCATION, $url);
        self::assertSame('text/xml; charset=utf-8', self::header($options, 'Content-Type'));
        self::assertSame('"urn:Echo#echo"', self::header($options, 'SOAPAction'));
        self::assertSame('Basic ' . base64_encode('vision:s3cret'), self::header($options, 'Authorization'));
        self::assertSame('PHP-SOAP/' . PHP_VERSION, self::header($options, 'User-Agent'));
        self::assertStringContainsString('<text>hello</text>', $options['body']);
    }

    public function testSoap12Request(): void {
        $client = new ProxySoapClient(
            $this->http(new MockResponse(self::RESPONSE_12, ['response_headers' => ['Content-Type: application/soap+xml; charset=utf-8']])),
            self::WSDL,
            ['soap_version' => SOAP_1_2],
        );

        self::assertSame('hello back', $client->echo('hello'));

        [, , $options] = $this->requests[0];
        self::assertSame('application/soap+xml; charset=utf-8; action="urn:Echo#echo"', self::header($options, 'Content-Type'));
        self::assertNull(self::header($options, 'SOAPAction'));
        self::assertNull(self::header($options, 'Authorization'));
    }

    public function testLocationOptionAndUserAgent(): void {
        $client = new ProxySoapClient(
            $this->http(new MockResponse(self::RESPONSE_11)),
            self::WSDL,
            ['location' => 'https://vision.example:8443/ws', 'user_agent' => 'timo-new-order'],
        );

        $client->echo('hello');

        [, $url, $options] = $this->requests[0];
        self::assertSame('https://vision.example:8443/ws', $url);
        self::assertSame('timo-new-order', self::header($options, 'User-Agent'));
    }

    public function testHttpOptionsReachTheRequest(): void {
        $pin = base64_encode(str_repeat('a', 32));
        $client = new ProxySoapClient(
            $this->http(new MockResponse(self::RESPONSE_11)),
            self::WSDL,
            [],
            ['peer_fingerprint' => ['pin-sha256' => [$pin]], 'timeout' => 25, 'verify_peer' => false, 'max_duration' => 60],
        );

        $client->echo('hello');

        [, , $options] = $this->requests[0];
        self::assertSame(['pin-sha256' => [$pin]], $options['peer_fingerprint']);
        self::assertEquals(25, $options['timeout']);
        self::assertFalse($options['verify_peer']);
        self::assertEquals(60, $options['max_duration']);
    }

    /**
     * @dataProvider reservedHttpOptionProvider
     */
    public function testReservedHttpOptionsAreRejected(string $name, $value): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("\"$name\"");

        new ProxySoapClient($this->http([]), self::WSDL, [], [$name => $value]);
    }

    public static function reservedHttpOptionProvider(): array {
        return [
            'body replaces the envelope' => ['body', 'x'],
            'headers replace Content-Type/SOAPAction' => ['headers', ['X-A' => 'b']],
            // These three would bypass the proxy: null/another URL, a matching
            // no_proxy, or an extra.curl that replaces the proxy's curl options.
            'proxy null connects directly' => ['proxy', null],
            'proxy elsewhere' => ['proxy', 'http://other-proxy.example:3128'],
            'no_proxy' => ['no_proxy', '*'],
            'extra.curl' => ['extra', ['curl' => [CURLOPT_VERBOSE => true]]],
        ];
    }

    public function testTransportErrorAtRequestTimeIsNotSent(): void {
        $http = new MockHttpClient(function () {
            throw new TransportException('Cannot use an "https://" proxy');
        });
        $client = new ProxySoapClient($http, self::WSDL);

        try {
            $client->echo('hello');
            self::fail('Expected ProxyTransportException');
        } catch (ProxyTransportException $e) {
            self::assertFalse($e->mayHaveReachedTarget());
            self::assertStringContainsString('Cannot use an "https://" proxy', $e->getMessage());
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }
    }

    public function testSoapFaultWith500IsParsed(): void {
        $client = new ProxySoapClient(
            $this->http(new MockResponse(self::FAULT_11, ['http_code' => 500, 'response_headers' => ['Content-Type: text/xml; charset=utf-8']])),
            self::WSDL,
        );

        try {
            $client->echo('hello');
            self::fail('Expected SoapFault');
        } catch (\SoapFault $fault) {
            self::assertSame('SOAP-ENV:Client.Validation', $fault->faultcode);
            self::assertSame('Unknown order', $fault->faultstring);
        }
    }

    public function testHttpErrorWithoutSoapBodyIsHttpFault(): void {
        $client = new ProxySoapClient(
            $this->http(new MockResponse('<html>busy</html>', ['response_headers' => ['HTTP/1.1 503 Service Unavailable', 'Content-Type: text/html']])),
            self::WSDL,
        );

        try {
            $client->echo('hello');
            self::fail('Expected SoapFault');
        } catch (\SoapFault $fault) {
            self::assertSame('HTTP', $fault->faultcode);
            self::assertSame('Service Unavailable', $fault->faultstring);
        }
    }

    public function testHttpErrorWithEmptyBodyIsHttpFault(): void {
        $client = new ProxySoapClient($this->http(new MockResponse('', ['http_code' => 502])), self::WSDL);

        try {
            $client->echo('hello');
            self::fail('Expected SoapFault');
        } catch (\SoapFault $fault) {
            self::assertSame('HTTP', $fault->faultcode);
            self::assertStringContainsString('502', $fault->faultstring);
        }
    }

    /**
     * Values from the k8s e2e harness (classification.json): every failure
     * before the request left (proxy 403, proxy down, target pin mismatch)
     * has pretransfer_time 0; a target timeout after the body was sent has it
     * above 0.
     *
     * @dataProvider transportErrorProvider
     */
    public function testTransportErrorIsClassified(array $info, bool $mayHaveReachedTarget): void {
        $client = new ProxySoapClient(
            $this->http(new MockResponse('', ['error' => 'CONNECT tunnel failed, response 403'] + $info)),
            self::WSDL,
        );

        try {
            $client->echo('hello');
            self::fail('Expected ProxyTransportException');
        } catch (ProxyTransportException $e) {
            self::assertSame($mayHaveReachedTarget, $e->mayHaveReachedTarget());
            self::assertStringContainsString('CONNECT tunnel failed, response 403', $e->getMessage());
            self::assertNotNull($e->getPrevious());
        }
    }

    public static function transportErrorProvider(): array {
        return [
            'not sent (pretransfer 0)' => [['pretransfer_time' => 0, 'pretransfer_time_us' => 0, 'size_upload' => 0], false],
            'sent (pretransfer > 0)' => [['pretransfer_time' => 0.0042, 'pretransfer_time_us' => 4200, 'size_upload' => 4096], true],
            'sent, GET-like (size_upload 0)' => [['pretransfer_time' => 0.0042, 'size_upload' => 0], true],
            'unknown (no pretransfer info) fails safe' => [[], true],
        ];
    }

    public function testTraceKeepsRequestAndResponse(): void {
        $client = new ProxySoapClient(
            $this->http(new MockResponse(self::RESPONSE_11, ['response_headers' => ['HTTP/1.1 200 OK', 'Content-Type: text/xml; charset=utf-8']])),
            self::WSDL,
            ['trace' => true, 'login' => 'vision', 'password' => 's3cret'],
        );

        $client->echo('hello');

        self::assertStringContainsString('<text>hello</text>', $client->__getLastRequest());
        self::assertSame(self::RESPONSE_11, $client->__getLastResponse());
        self::assertStringStartsWith("POST /soap HTTP/1.1\r\nHost: echo.example\r\n", $client->__getLastRequestHeaders());
        self::assertStringContainsString("SOAPAction: \"urn:Echo#echo\"\r\n", $client->__getLastRequestHeaders());
        self::assertStringContainsString("Content-Type: text/xml; charset=utf-8", $client->__getLastResponseHeaders());
    }

    /**
     * @dataProvider unsupportedOptionProvider
     */
    public function testUnsupportedOptionsAreRejected(array $options): void {
        $this->expectException(\InvalidArgumentException::class);

        new ProxySoapClient($this->http([]), self::WSDL, $options);
    }

    public static function unsupportedOptionProvider(): array {
        return [
            'stream_context' => [['stream_context' => stream_context_create()]],
            'proxy_host' => [['proxy_host' => 'proxy.example']],
            'proxy_port' => [['proxy_port' => 3128]],
            'proxy_login' => [['proxy_login' => 'u']],
            'proxy_password' => [['proxy_password' => 'p']],
            'connection_timeout' => [['connection_timeout' => 5]],
            'local_cert' => [['local_cert' => '/tmp/cert.pem']],
            'passphrase' => [['passphrase' => 'x']],
            'digest auth' => [['login' => 'u', 'password' => 'p', 'authentication' => SOAP_AUTHENTICATION_DIGEST]],
            'request compression' => [['compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP | 5]],
            'exceptions off' => [['exceptions' => false]],
        ];
    }

    public function testRemoteWsdlIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('local');

        new ProxySoapClient($this->http([]), 'https://vision.example/ws?wsdl');
    }

    public function testAcceptCompressionIsAllowed(): void {
        $client = new ProxySoapClient($this->http(new MockResponse(self::RESPONSE_11)), self::WSDL, ['compression' => SOAP_COMPRESSION_ACCEPT]);

        self::assertSame('hello back', $client->echo('hello'));
    }
}
