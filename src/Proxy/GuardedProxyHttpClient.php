<?php

namespace Simplia\Integration\Proxy;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
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
    /**
     * Options only the proxy client itself may set. This guard still accepts
     * extra.trace_content (see sanitize()); ProxySoapClient rejects 'extra' entirely.
     */
    public const RESERVED_OPTIONS = ['proxy', 'no_proxy', 'extra'];

    private HttpClientInterface $inner;

    public function __construct(HttpClientInterface $inner) {
        $this->inner = $inner;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface {
        return $this->inner->request($method, $url, self::sanitize($options));
    }

    public function stream($responses, ?float $timeout = null): ResponseStreamInterface {
        return $this->inner->stream($responses, $timeout);
    }

    /** Not part of the contracts version we require, but TraceableHttpClient calls it. */
    public function withOptions(array $options): self {
        $options = self::sanitize($options);
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);

        return $clone;
    }

    public function reset(): void {
        if ($this->inner instanceof ResetInterface) {
            $this->inner->reset();
        }
    }

    public function setLogger(LoggerInterface $logger): void {
        if ($this->inner instanceof LoggerAwareInterface) {
            $this->inner->setLogger($logger);
        }
    }

    /**
     * Rejects the reserved options and returns the options for the inner client.
     *
     * 'extra' is allowed only as ['trace_content' => ...]: TraceableHttpClient (which wraps this
     * guard) reads it but forwards it unchanged, and Symfony merges 'extra' shallowly, so passing it
     * on would replace the client's default extra.curl (proxy certificate, tunnel settings). It is
     * therefore removed here. Any other extra key, curl included, is rejected.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    public static function sanitize(array $options): array {
        if (array_key_exists('extra', $options)) {
            $extra = $options['extra'];
            if (!is_array($extra) || array_diff_key($extra, ['trace_content' => true]) !== []) {
                throw new \InvalidArgumentException('HTTP option "extra" is managed by the integration proxy client; only extra.trace_content can be set per request');
            }
            unset($options['extra']);
        }
        foreach (['proxy', 'no_proxy'] as $name) {
            if (array_key_exists($name, $options)) {
                throw new \InvalidArgumentException("HTTP option \"$name\" is managed by the integration proxy client and cannot be set per request");
            }
        }

        return $options;
    }
}
