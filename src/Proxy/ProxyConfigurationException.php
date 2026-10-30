<?php

namespace Simplia\Integration\Proxy;

/**
 * The integration proxy is required but its configuration is missing or malformed.
 * There is no fallback to a direct connection.
 */
class ProxyConfigurationException extends \RuntimeException {
}
