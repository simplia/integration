<?php

namespace Simplia\Integration;

use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\Ssm\SsmClient;
use Bref\Context\Context as BrefContext;
use \Bref\Event\Handler as BrefHandler;
use Pkerrigan\Xray\Submission\DaemonSegmentSubmitter;
use Pkerrigan\Xray\Trace;
use RuntimeException;
use Simplia\Api\Api;
use Simplia\Integration\Event\EventDecoder;
use Simplia\Integration\Storage\KeyValueStorage;
use Simplia\Integration\Storage\LocalKeyValueStorage;
use Simplia\Integration\Storage\RemoteKeyValueStorage;
use Simplia\Integration\Trace\HttpSegment;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\TraceableHttpClient;

class Handler implements BrefHandler {

    /** @var callable */
    private $handler;

    public function __construct(callable $handler) {
        $this->handler = $handler;
    }

    private ?Trace $trace;

    public function handle($event, BrefContext $context) {
        if (!empty($_ENV['XRAY_ENABLED'])) {
            $this->startTracing($context);
        }
        $http = new TraceableHttpClient(HttpClient::create());
        $apiHttp = new Psr18Client($http);
        $credentials = $this->getCredentialsData();
        $api = Api::withUsernameAuth($apiHttp, $credentials['shop']['host'], $credentials['shop']['user'], $credentials['shop']['password']);
        $host = $credentials['shop']['host'];
        unset($credentials['shop']);

        $fn = $this->handler;
        $response = $fn(new Context(
            $http,
            $api,
            $this->getKeyValueStorage(),
            $credentials,
            EventDecoder::fromInput($event)
        ));

        if (!empty($response)) {
            echo json_encode($response, JSON_THROW_ON_ERROR);
        }

        if (!empty($_ENV['XRAY_ENABLED'])) {
            $this->endTracing($http, 'https://' . $host . '/api/2/');
        }
    }

    private function getKeyValueStorage(): KeyValueStorage {
        if ($_ENV['CREDENTIALS_PARAMETER_PATH'] && $_ENV['AWS_LAMBDA_FUNCTION_NAME']) {
            return new RemoteKeyValueStorage(new DynamoDbClient(['region' => $_ENV['AWS_REGION']]), 'persistent-storage', $_ENV['AWS_LAMBDA_FUNCTION_NAME']);
        }

        return new LocalKeyValueStorage(__DIR__ . '/../../../.storage');
    }

    private function getCredentialsData(): array {
        if ($_ENV['CREDENTIALS_PARAMETER_PATH']) {
            $ssm = new SsmClient([
                'region' => $_ENV['AWS_DEFAULT_REGION'],
            ]);
            $credentialsParameter = $ssm->getParameter([
                'Name' => $_ENV['CREDENTIALS_PARAMETER_PATH'],
                'WithDecryption' => true,
            ])->getParameter();
            if (!$credentialsParameter) {
                throw new RuntimeException('Cannot load credentials');
            }
            $credentialsContent = $credentialsParameter->getValue();
        } else {
            $credentialsContent = file_get_contents(__DIR__ . '/../../../.credentials.json');
        }

        return json_decode($credentialsContent, true, 512, JSON_THROW_ON_ERROR);
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
