<?php

namespace Simplia\Integration\Proxy;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Wraps the tunnelling client and refuses the per-request options that would
 * let a request leave the tunnel: Symfony lets request options win over the
 * client defaults, so 'proxy' => null connects directly, 'no_proxy' exempts a
 * host, and 'extra' (merged shallowly) replaces the proxy's TLS settings.
 */
final class GuardedProxyHttpClient implements HttpClientInterface, ResetInterface, LoggerAwareInterface {
    /** Options only the proxy client itself may set. */
    public const RESERVED_OPTIONS = ['proxy', 'no_proxy', 'extra'];

    private CurlHttpClient $inner;

    public function __construct(CurlHttpClient $inner) {
        $this->inner = $inner;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface {
        self::assertAllowed($options);

        return $this->inner->request($method, $url, $options);
    }

    public function stream($responses, ?float $timeout = null): ResponseStreamInterface {
        return $this->inner->stream($responses, $timeout);
    }

    /** Not part of the contracts version we require, but TraceableHttpClient calls it. */
    public function withOptions(array $options): self {
        self::assertAllowed($options);
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);

        return $clone;
    }

    public function reset(): void {
        $this->inner->reset();
    }

    public function setLogger(LoggerInterface $logger): void {
        $this->inner->setLogger($logger);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function assertAllowed(array $options): void {
        foreach (self::RESERVED_OPTIONS as $name) {
            if (array_key_exists($name, $options)) {
                throw new \InvalidArgumentException("HTTP option \"$name\" is managed by the integration proxy client and cannot be set per request");
            }
        }
    }
}
