<?php

namespace Simplia\Integration\Proxy;

/**
 * Where the integration proxy is and the client identity used to reach it:
 * the proxy URL plus the client certificate, its private key and the CA of the
 * proxy's server certificate, all as PEM text (never files).
 */
final class ProxyConfig {
    private const PEM_PREFIX = '-----BEGIN';

    private function __construct(
        public readonly string $url,
        public readonly string $cert,
        #[\SensitiveParameter] public readonly string $key,
        public readonly string $ca,
    ) {
    }

    /**
     * @param string $json the SSM parameter value, JSON {"cert", "key", "ca"}
     * @param string $parameterPath the SSM parameter name, used in error messages
     */
    public static function fromParameterJson(string $url, #[\SensitiveParameter] string $json, string $parameterPath): self {
        $source = "SSM parameter $parameterPath";
        try {
            $values = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProxyConfigurationException("Integration proxy: $source is not valid JSON ({$e->getMessage()})");
        }
        if (!is_array($values)) {
            throw new ProxyConfigurationException("Integration proxy: $source must be a JSON object with \"cert\", \"key\" and \"ca\"");
        }

        return self::build($url, $values, ['cert' => '"cert"', 'key' => '"key"', 'ca' => '"ca"'], $source);
    }

    /**
     * Local development: the same variables as a Lambda, with the PEM values
     * themselves instead of an SSM parameter path.
     *
     * @param array<string, mixed> $env INTEGRATION_PROXY_URL, INTEGRATION_PROXY_CERT, INTEGRATION_PROXY_KEY, INTEGRATION_PROXY_CA
     * @return self|null null when INTEGRATION_PROXY_URL is not set (proxy not used)
     */
    public static function fromLocalEnvironment(#[\SensitiveParameter] array $env): ?self {
        if (!array_key_exists('INTEGRATION_PROXY_URL', $env)) {
            return null;
        }
        $names = ['cert' => 'INTEGRATION_PROXY_CERT', 'key' => 'INTEGRATION_PROXY_KEY', 'ca' => 'INTEGRATION_PROXY_CA'];
        $values = [];
        foreach ($names as $field => $name) {
            if (array_key_exists($name, $env)) {
                // docker --env-file cannot hold multi-line values, so accept one-line PEMs with literal \n.
                $values[$field] = is_string($env[$name]) ? str_replace('\\n', "\n", $env[$name]) : $env[$name];
            }
        }

        return self::build((string)$env['INTEGRATION_PROXY_URL'], $values, $names, 'environment (INTEGRATION_PROXY_URL is set)');
    }

    /**
     * @param array<string, string> $labels field => name used in error messages
     */
    private static function build(string $url, #[\SensitiveParameter] array $values, array $labels, string $source): self {
        if (!str_starts_with($url, 'https://') || parse_url($url, PHP_URL_HOST) === null) {
            throw new ProxyConfigurationException("Integration proxy: URL \"$url\" must be https://host:port (for $source)");
        }
        foreach ($labels as $field => $label) {
            $value = $values[$field] ?? null;
            if (!is_string($value) || $value === '') {
                throw new ProxyConfigurationException("Integration proxy: $label is missing or empty in $source");
            }
            if (!str_starts_with($value, self::PEM_PREFIX)) {
                throw new ProxyConfigurationException("Integration proxy: $label in $source is not PEM (must start with " . self::PEM_PREFIX . ')');
            }
        }

        return new self($url, $values['cert'], $values['key'], $values['ca']);
    }

    public function __debugInfo(): array {
        return ['url' => $this->url, 'cert' => $this->cert, 'key' => '(redacted)', 'ca' => $this->ca];
    }
}
