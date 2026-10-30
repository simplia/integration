<?php

namespace Simplia\Integration\Proxy;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * SoapClient whose HTTP transport is a Symfony HTTP client, normally
 * ProxyHttpClient::create() so the calls go through the integration proxy.
 * PHP's own SOAP transport cannot nest TLS inside an mTLS proxy connection.
 *
 * Reproduces the native transport: POST to the location, Content-Type and
 * SOAPAction for SOAP 1.1 / action parameter for SOAP 1.2, Basic auth from
 * login/password, User-Agent (user_agent option or PHP-SOAP/<version>), a
 * non-2xx response with a SOAP body is parsed (SoapFault from the server's
 * fault), any other HTTP error is SoapFault "HTTP" with the reason phrase.
 * The read timeout defaults to default_socket_timeout, as natively; set
 * 'timeout' / 'max_duration' in $httpOptions to change it. With 'trace' the
 * __getLastRequest*() and __getLastResponse*() methods work as usual.
 *
 * A transport error (no HTTP response) is a ProxyTransportException that says
 * whether the request may have reached the third party.
 *
 * Native transport options that this transport would silently ignore are
 * rejected: put TLS settings for the target into $httpOptions instead
 * (verify_peer, verify_host, cafile, peer_fingerprint with 'pin-sha256').
 */
class ProxySoapClient extends \SoapClient {
    private const UNSUPPORTED_OPTIONS = [
        'stream_context' => 'set target TLS options in $httpOptions',
        'proxy_host' => 'the HTTP client decides the proxy',
        'proxy_port' => 'the HTTP client decides the proxy',
        'proxy_login' => 'the HTTP client decides the proxy',
        'proxy_password' => 'the HTTP client decides the proxy',
        'connection_timeout' => 'set "timeout" / "max_duration" in $httpOptions',
        'local_cert' => 'client certificates toward the target are not supported',
        'passphrase' => 'client certificates toward the target are not supported',
        'ssl_method' => 'TLS versions are negotiated by curl',
    ];

    /**
     * HTTP client options that would replace the SOAP request itself, or route
     * around the proxy: a per-request 'proxy' (null = direct) or 'no_proxy'
     * wins over the client's defaults, and 'extra' is merged shallowly, so a
     * caller's extra.curl would drop the proxy's TLS and tunnel settings.
     */
    private const RESERVED_HTTP_OPTIONS = ['body', 'json', 'headers', 'auth_basic', 'auth_bearer', 'query', 'base_uri', 'proxy', 'no_proxy', 'extra'];

    private HttpClientInterface $http;
    private array $httpOptions;
    private ?string $login;
    private ?string $password;
    private string $userAgent;
    private bool $trace;

    /**
     * @param string|null $wsdl a local WSDL file (a remote one would be downloaded without the proxy), or null in non-WSDL mode
     * @param array $options SoapClient options
     * @param array $httpOptions Symfony request options for every call (timeout, max_duration, verify_peer, peer_fingerprint, ...)
     */
    public function __construct(HttpClientInterface $http, ?string $wsdl, array $options = [], array $httpOptions = []) {
        foreach (self::UNSUPPORTED_OPTIONS as $name => $hint) {
            if (array_key_exists($name, $options)) {
                throw new \InvalidArgumentException("SoapClient option \"$name\" is not supported by ProxySoapClient: $hint");
            }
        }
        if (($options['authentication'] ?? SOAP_AUTHENTICATION_BASIC) !== SOAP_AUTHENTICATION_BASIC) {
            throw new \InvalidArgumentException('ProxySoapClient supports only Basic authentication (SOAP_AUTHENTICATION_BASIC)');
        }
        if ((($options['compression'] ?? 0) & 0x0f) !== 0) {
            throw new \InvalidArgumentException('ProxySoapClient does not compress requests; only SOAP_COMPRESSION_ACCEPT is supported');
        }
        if (array_key_exists('exceptions', $options) && !$options['exceptions']) {
            throw new \InvalidArgumentException('ProxySoapClient requires "exceptions" => true (transport errors are always thrown)');
        }
        foreach (self::RESERVED_HTTP_OPTIONS as $name) {
            if (array_key_exists($name, $httpOptions)) {
                throw new \InvalidArgumentException("HTTP option \"$name\" is set by ProxySoapClient itself");
            }
        }
        if ($wsdl !== null && preg_match('~^[a-z][a-z0-9+.-]*://~i', $wsdl) && !str_starts_with(strtolower($wsdl), 'file://')) {
            throw new \InvalidArgumentException("ProxySoapClient needs a local WSDL file; \"$wsdl\" would be downloaded without the proxy");
        }

        $this->http = $http;
        $this->httpOptions = $httpOptions;
        $this->login = isset($options['login']) ? (string)$options['login'] : null;
        $this->password = isset($options['password']) ? (string)$options['password'] : null;
        $this->userAgent = isset($options['user_agent']) ? (string)$options['user_agent'] : 'PHP-SOAP/' . PHP_VERSION;
        $this->trace = (bool)($options['trace'] ?? false);

        parent::__construct($wsdl, $options);
    }

    public function __doRequest(string $request, string $location, string $action, int $version, bool $oneWay = false): ?string {
        if ($version === SOAP_1_2) {
            $headers = ['Content-Type' => 'application/soap+xml; charset=utf-8; action="' . $action . '"'];
        } else {
            $headers = ['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => '"' . $action . '"'];
        }
        $headers['User-Agent'] = $this->userAgent;

        $options = ['headers' => $headers, 'body' => $request] + $this->httpOptions;
        if ($this->login !== null) {
            $options['auth_basic'] = [$this->login, (string)$this->password];
        }
        if ($this->trace) {
            $this->setLastHeaders('__last_request_headers', $this->requestHeaders($location, $headers));
        }

        $response = null;
        try {
            $response = $this->http->request('POST', $location, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
            $responseHeaders = $response->getHeaders(false);
        } catch (TransportExceptionInterface $e) {
            // request() only prepares the transfer: an error there means nothing was sent.
            throw $response === null
                ? new ProxyTransportException($e->getMessage(), false, $e)
                : ProxyTransportException::fromTransport($e, $response->getInfo());
        }

        if ($this->trace) {
            $this->setLastHeaders('__last_response_headers', implode("\r\n", (array)$response->getInfo('response_headers')) . "\r\n");
        }

        if ($status >= 400 && !self::isSoapBody($body, $responseHeaders['content-type'][0] ?? '')) {
            throw new \SoapFault('HTTP', self::reasonPhrase($response->getInfo('response_headers'), $status));
        }

        return $body;
    }

    /**
     * PHP's transport hands an error response to the SOAP parser only if it
     * is XML (by Content-Type, or by an <?xml prolog).
     */
    private static function isSoapBody(string $body, string $contentType): bool {
        if ($body === '') {
            return false;
        }
        $mediaType = strtolower(trim(explode(';', $contentType)[0]));
        if ($mediaType === 'text/xml' || $mediaType === 'application/soap+xml') {
            return true;
        }

        return str_starts_with(ltrim($body, "\x00..\x1F"), '<?xml');
    }

    private static function reasonPhrase(mixed $responseHeaders, int $status): string {
        $statusLine = null;
        foreach ((array)$responseHeaders as $line) {
            if (is_string($line) && str_starts_with($line, 'HTTP/')) {
                $statusLine = $line;
            }
        }
        if ($statusLine !== null && preg_match('~^HTTP/\S+\s+\d{3}\s+(.+)$~', $statusLine, $m)) {
            return trim($m[1]);
        }

        return "HTTP status $status";
    }

    private function requestHeaders(string $location, array $headers): string {
        $url = parse_url($location);
        $target = ($url['path'] ?? '/') . (isset($url['query']) ? '?' . $url['query'] : '');
        $host = ($url['host'] ?? '') . (isset($url['port']) ? ':' . $url['port'] : '');
        $lines = ["POST $target HTTP/1.1", "Host: $host"];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        if ($this->login !== null) {
            $lines[] = 'Authorization: Basic ' . base64_encode($this->login . ':' . $this->password);
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * The native transport fills these private SoapClient properties; the
     * __getLast*Headers() methods read them.
     */
    private function setLastHeaders(string $property, string $value): void {
        (new \ReflectionProperty(\SoapClient::class, $property))->setValue($this, $value);
    }
}
