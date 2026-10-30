<?php

namespace Simplia\Integration\Proxy;

use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP client that tunnels every request through the integration proxy: curl
 * opens an mTLS connection to the proxy (client cert, key and CA as PEM blobs),
 * sends CONNECT host:port and then speaks TLS end to end with the target.
 *
 * The result is a plain Symfony client, so failures surface as Symfony's own
 * exceptions. To know whether a failed request may have reached the third
 * party, convert a transport error yourself:
 *
 *     $response = null;
 *     try {
 *         $response = $client->request('POST', $url, $options);
 *         $content = $response->getContent();
 *     } catch (TransportExceptionInterface $e) {
 *         throw $response === null
 *             ? new ProxyTransportException($e->getMessage(), false, $e) // request() sends nothing
 *             : ProxyTransportException::fromTransport($e, $response->getInfo());
 *     }
 *
 * Target TLS options are ordinary request options: verify_peer, verify_host,
 * cafile and peer_fingerprint (curl supports only 'pin-sha256' public key pins).
 *
 * Never pass these per request, Symfony lets them win over the defaults below:
 * - 'proxy' (null connects directly, another URL uses another proxy) and
 *   'no_proxy' (a matching host connects directly);
 * - 'extra': it is merged shallowly, so your own extra.curl replaces the
 *   proxy's client certificate, CA, tunnel and CONNECT-header settings.
 */
final class ProxyHttpClient {
    public static function create(ProxyConfig $config): HttpClientInterface {
        return new CurlHttpClient([
            'proxy' => $config->url,
            // An empty list instead of null, so no_proxy/NO_PROXY from the environment cannot route around the proxy.
            'no_proxy' => '',
            'extra' => [
                'curl' => [
                    CURLOPT_HTTPPROXYTUNNEL => true,
                    CURLOPT_PROXY_SSLCERT_BLOB => $config->cert,
                    CURLOPT_PROXY_SSLKEY_BLOB => $config->key,
                    CURLOPT_PROXY_CAINFO_BLOB => $config->ca,
                    // Without this the proxy's CONNECT reply becomes the response: a refused
                    // tunnel would read as getStatusCode() 403 from the target, not as a transport error.
                    CURLOPT_SUPPRESS_CONNECT_HEADERS => true,
                ],
            ],
        ]);
    }
}
