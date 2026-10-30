<?php

namespace Simplia\Integration\Proxy;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * A request through the integration proxy failed without an HTTP response.
 *
 * mayHaveReachedTarget() tells whether the third party may have received the
 * request: false means it certainly did not (proxy unreachable, mTLS failure,
 * proxy 403/503 on CONNECT, target TLS or pin failure), so a retry cannot
 * duplicate anything; true means it may have been processed (a timeout or a
 * reset after the request started), so retry only idempotent operations.
 */
final class ProxyTransportException extends \RuntimeException {
    private bool $mayHaveReachedTarget;

    public function __construct(string $message, bool $mayHaveReachedTarget, ?\Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
        $this->mayHaveReachedTarget = $mayHaveReachedTarget;
    }

    public function mayHaveReachedTarget(): bool {
        return $this->mayHaveReachedTarget;
    }

    /**
     * Classifies a Symfony transport error by curl's pretransfer time, which is
     * set only once the CONNECT tunnel and the TLS handshake with the target
     * (including the pin check) succeeded and the request started to go out.
     * Without that information the request is treated as possibly sent.
     *
     * @param array $info $response->getInfo() of the failed response
     */
    public static function fromTransport(TransportExceptionInterface $e, array $info): self {
        if (!array_key_exists('pretransfer_time', $info) && !array_key_exists('pretransfer_time_us', $info)) {
            $mayHaveReachedTarget = true;
        } else {
            $mayHaveReachedTarget = (float)($info['pretransfer_time'] ?? 0) > 0 || (int)($info['pretransfer_time_us'] ?? 0) > 0;
        }

        return new self($e->getMessage(), $mayHaveReachedTarget, $e);
    }
}
