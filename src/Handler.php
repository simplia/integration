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
            [$credentials, $shopCredentials, $host] = $this->getCredentialsData($http);
            $api = Api::withJWT($apiHttp, $host, $shopCredentials);

            $fn = $this->handler;
            $typedInput = EventDecoder::fromInput($event);
            $response = $fn(new Context(
                $http,
                $api,
                $this->getKeyValueStorage(),
                $this->getFileStorage(),
                $credentials,
                $typedInput
            ), $typedInput);

            if (!empty($_ENV['XRAY_ENABLED'])) {
                $this->endTracing($http, 'https://' . $host . '/api/2/');
            }
        } catch (\Throwable $exception) {
            captureException($exception);
            if (!empty($_ENV['XRAY_ENABLED']) && isset($http, $host)) {
                $this->trace->setError(true);
                $this->endTracing($http, 'https://' . $host . '/api/2/');
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

    private function getCredentialsData(HttpClientInterface $httpClient): array {
        $shopCredentials = null;
        if (isset($_ENV['CREDENTIALS_PARAMETER_PATH'])) {
            $ssm = new SsmClient([
                'region' => $_ENV['AWS_DEFAULT_REGION'],
            ]);
            $credentialsParameter = null;
            foreach ($ssm->getParameters([
                'Names' => [$_ENV['CREDENTIALS_PARAMETER_PATH'], $_ENV['SHOP_CREDENTIALS_PARAMETER_PATH']],
                'WithDecryption' => true,
            ])->getParameters() as $parameter) {
                if ($parameter->getName() === $_ENV['CREDENTIALS_PARAMETER_PATH']) {
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
        }

        return [
            $credentialsContent,
            $shopCredentials,
            $host,
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

    private function endTracing(TraceableHttpClient $httpClient, string $apiPrefix): void {
        [$host, $port] = explode(':', $_ENV['AWS_XRAY_DAEMON_ADDRESS']);

        foreach ($httpClient->getTracedRequests() as $request) {
            $segment = new HttpSegment();
            $name = parse_url($request['url'], PHP_URL_HOST);
            if (strpos($request['url'], $apiPrefix) === 0) {
                $name = 'API ' . $name;
            }
            $segment
                ->setUrl($request['url'])
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
