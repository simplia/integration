<?php

namespace Simplia\Integration;

use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\S3\S3Client;
use AsyncAws\Ssm\SsmClient;
use Bref\Context\Context as BrefContext;
use \Bref\Event\Handler as BrefHandler;
use Pkerrigan\Xray\Submission\DaemonSegmentSubmitter;
use Pkerrigan\Xray\Trace;
use RuntimeException;
use Simplia\Api\Api;
use Simplia\Integration\Event\EventDecoder;
use Simplia\Integration\Event\Export\ExportTransformEvent;
use Simplia\Integration\Proxy\ProxyConfig;
use Simplia\Integration\Proxy\ProxyConfigurationException;
use Simplia\Integration\Proxy\ProxyHttpClient;
use Simplia\Integration\Storage\FileStorage;
use Simplia\Integration\Storage\KeyValueStorage;
use Simplia\Integration\Storage\LocalFileStorage;
use Simplia\Integration\Storage\LocalKeyValueStorage;
use Simplia\Integration\Storage\RemoteFileStorage;
use Simplia\Integration\Storage\RemoteKeyValueStorage;
use Simplia\Integration\Trace\HttpSegment;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\TraceableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function Sentry\captureException;

class Handler implements BrefHandler {

    /** @var callable */
    private $handler;

    public function __construct(callable $handler) {
        $this->handler = $handler;
    }

    private Trace $trace;

    public function handle($event, BrefContext $context) {
        if (!empty($_ENV['XRAY_ENABLED'])) {
            $this->startTracing($context);
        }
        try {
            $http = new TraceableHttpClient(HttpClient::create(['timeout' => 5 * 60]));
            $apiHttp = new Psr18Client($http);
            [$credentials, $shopCredentials, $host, $proxyConfig] = $this->getCredentialsData($http);
            // Traced separately: the proxy client must never be reachable through (or be replaced by) the plain client.
            $proxyHttp = $proxyConfig !== null ? new TraceableHttpClient(ProxyHttpClient::create($proxyConfig)) : null;
            $api = Api::withJWT($apiHttp, $host, $shopCredentials);

            $fn = $this->handler;
            $typedInput = EventDecoder::fromInput($event);
            if ($typedInput instanceof ExportTransformEvent) {
                $typedInput->setHttpClient($http);
            }
            $response = $fn(new Context(
                $http,
                $api,
                $this->getKeyValueStorage(),
                $this->getFileStorage(),
                $credentials,
                $typedInput,
                $proxyHttp
            ), $typedInput);

            if (!empty($_ENV['XRAY_ENABLED'])) {
                $this->endTracing([$http, $proxyHttp ?? null], 'https://' . $host . '/api/3/');
            }
        } catch (\Throwable $exception) {
            captureException($exception);
            if (!empty($_ENV['XRAY_ENABLED']) && isset($http, $host)) {
                $this->trace->setError(true);
                $this->endTracing([$http, $proxyHttp ?? null], 'https://' . $host . '/api/3/');
            }
            throw $exception;
        }

        return $response ?? null;
    }

    private function getKeyValueStorage(): KeyValueStorage {
        if (isset($_ENV['CREDENTIALS_PARAMETER_PATH'], $_ENV['AWS_LAMBDA_FUNCTION_NAME'])) {
            return new RemoteKeyValueStorage(new DynamoDbClient(['region' => $_ENV['AWS_REGION']]), 'persistent-storage', $_ENV['AWS_LAMBDA_FUNCTION_NAME']);
        }

        return new LocalKeyValueStorage(__DIR__ . '/../../../.storage.json');
    }

    private function getFileStorage(): FileStorage {
        if (isset($_ENV['CREDENTIALS_PARAMETER_PATH'], $_ENV['AWS_LAMBDA_FUNCTION_NAME'])) {
            return new RemoteFileStorage(new S3Client(['region' => $_ENV['AWS_REGION']]), 'persistent-storage-integration-simplia', $_ENV['AWS_LAMBDA_FUNCTION_NAME']);
        }

        if (!file_exists(__DIR__ . '/../../../.storage') && !mkdir($concurrentDirectory = __DIR__ . '/../../../.storage') && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }

        return new LocalFileStorage(__DIR__ . '/../../../.storage');
    }

    /**
     * @param array<string, mixed> $env
     * @param array<string, string> $parametersByName decrypted SSM values by parameter name
     * @throws ProxyConfigurationException when the instance is opted in but the proxy config cannot be built
     */
    public static function proxyConfigFromParameters(#[\SensitiveParameter] array $env, #[\SensitiveParameter] array $parametersByName): ?ProxyConfig {
        $path = $env['INTEGRATION_PROXY_PARAMETER_PATH'] ?? null;
        if ($path === null || $path === '') {
            return null;
        }
        if (empty($env['INTEGRATION_PROXY_URL'])) {
            throw new ProxyConfigurationException('INTEGRATION_PROXY_URL is not set although INTEGRATION_PROXY_PARAMETER_PATH is');
        }
        if (!isset($parametersByName[$path])) {
            throw new ProxyConfigurationException("Integration proxy is enabled but SSM parameter $path was not found");
        }

        return ProxyConfig::fromParameterJson($env['INTEGRATION_PROXY_URL'], $parametersByName[$path], $path);
    }

    private function getCredentialsData(HttpClientInterface $httpClient): array {
        $shopCredentials = null;
        $proxyConfig = null;
        if (isset($_ENV['CREDENTIALS_PARAMETER_PATH'])) {
            $ssm = new SsmClient([
                'region' => $_ENV['AWS_DEFAULT_REGION'],
            ]);
            $credentialsParameter = null;
            $names = [$_ENV['CREDENTIALS_PARAMETER_PATH'], $_ENV['SHOP_CREDENTIALS_PARAMETER_PATH']];
            if (!empty($_ENV['INTEGRATION_PROXY_PARAMETER_PATH'])) {
                $names[] = $_ENV['INTEGRATION_PROXY_PARAMETER_PATH'];
            }
            $proxyParameters = [];
            foreach ($ssm->getParameters([
                'Names' => $names,
                'WithDecryption' => true,
            ])->getParameters() as $parameter) {
                if (!empty($_ENV['INTEGRATION_PROXY_PARAMETER_PATH']) && $parameter->getName() === $_ENV['INTEGRATION_PROXY_PARAMETER_PATH']) {
                    $proxyParameters[$parameter->getName()] = $parameter->getValue();
                } elseif ($parameter->getName() === $_ENV['CREDENTIALS_PARAMETER_PATH']) {
                    $credentialsParameter = $parameter;
                } elseif ($parameter->getName() === $_ENV['SHOP_CREDENTIALS_PARAMETER_PATH']) {
                    $shopCredentials = json_decode($parameter->getValue(), true)['JWT'];
                }
            }
            if (!$credentialsParameter) {
                throw new RuntimeException('Cannot load credentials');
            }
            $credentialsContent = json_decode($credentialsParameter->getValue(), true, 512, JSON_THROW_ON_ERROR);
            $host = $this->getHostFromJWT($shopCredentials);
            // Before the user handler runs: an opted-in instance without its parameter must fail here, not on first use.
            $proxyConfig = self::proxyConfigFromParameters($_ENV, $proxyParameters);
        } else {
            $shopCredentials = $_ENV['CREDENTIALS'];
            $host = $this->getHostFromJWT($shopCredentials);
            $localAttributes = [];
            $path = __DIR__ . '/../../../.credentials.json';
            if ($_ENV['ATTRIBUTES_MODE'] !== 'remote' && file_exists($path)) {
                $localAttributes = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            }
            $remoteAttributes = [];
            if ($_ENV['ATTRIBUTES_MODE'] !== 'local') {
                $remoteAttributes = json_decode($httpClient->request('GET', 'https://' . $host . '/_/integration/attributes', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . base64_encode($shopCredentials),
                    ],
                ])->getContent(), true, 512, JSON_THROW_ON_ERROR);
            }
            $credentialsContent = array_merge($remoteAttributes,$localAttributes);
            $proxyConfig = ProxyConfig::fromLocalEnvironment($_ENV);
        }

        return [
            $credentialsContent,
            $shopCredentials,
            $host,
            $proxyConfig,
        ];
    }

    private function getHostFromJWT(string $token): string {
        [, $shopMeta,] = explode('.', $token);

        return json_decode(base64_decode($shopMeta, true), true, 512, JSON_THROW_ON_ERROR)['host'];
    }

    private function startTracing(BrefContext $context): void {
        $this->trace = new Trace();
        $this->trace
            ->setTraceHeader($context->getTraceId())
            ->begin(100)
            ->setName($_ENV['INTEGRATION_REPOSITORY'] . '--' . $_ENV['INTEGRATION_SHOP'])
            ->addAnnotation('Repository', $_ENV['INTEGRATION_REPOSITORY'])
            ->addAnnotation('Shop', $_ENV['INTEGRATION_SHOP'])
            ->addAnnotation('Version', $_ENV['INTEGRATION_REPOSITORY_VERSION']);
    }

    /**
     * @param array{0: TraceableHttpClient, 1: TraceableHttpClient|null} $httpClients plain client, proxy client
     */
    private function endTracing(array $httpClients, string $apiPrefix): void {
        [$host, $port] = explode(':', $_ENV['AWS_XRAY_DAEMON_ADDRESS']);

        $requests = [];
        foreach ($httpClients as $i => $httpClient) {
            foreach ($httpClient?->getTracedRequests() ?? [] as $request) {
                $requests[] = [$request, $i === 1];
            }
        }
        // Only url, method and timings are recorded, never bodies or headers.
        foreach ($requests as [$request, $viaProxy]) {
            $segment = new HttpSegment();
            $url = $request['url'];
            if ($viaProxy || str_contains($url, 'X-Amz-')) {
                // Presigned S3 URLs carry live signatures in the query string, and third-party
                // URLs reached through the proxy often carry API keys there;
                // keep them out of X-Ray traces (rendered in shop admin pages).
                $url = explode('?', $url, 2)[0];
            }
            $name = parse_url($url, PHP_URL_HOST);
            if (strpos($request['url'], $apiPrefix) === 0) {
                $name = 'API ' . $name;
            }
            $segment
                ->setUrl($url)
                ->setMethod($request['method'])
                ->setName($name)
                ->setResponseCode($request['info']['http_code'])
                ->setStartEnd($request['info']['start_time'], $request['info']['start_time'] + $request['info']['total_time']);
            $this->trace->addSubsegment($segment);
        }

        $this->trace
            ->end()
            ->submit(new DaemonSegmentSubmitter($host, (int) $port));
    }
}
