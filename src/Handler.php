<?php

namespace Simplia\Integration;

use AsyncAws\Ssm\SsmClient;
use Bref\Context\Context as BrefContext;
use \Bref\Event\Handler as BrefHandler;
use Pkerrigan\Xray\Submission\DaemonSegmentSubmitter;
use Pkerrigan\Xray\Trace;
use RuntimeException;
use Simplia\Api\Api;
use Simplia\Integration\Event\EventDecoder;
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

    public function handle($event, BrefContext $context) {
        $this->startTracing();
        $http = new TraceableHttpClient(HttpClient::create());
        $apiHttp = new Psr18Client($http);
        $credentials = $this->getCredentialsData();
        $api = Api::withUsernameAuth($apiHttp, $credentials['shop']['host'], $credentials['shop']['user'], $credentials['shop']['password']);
        unset($credentials['shop']);

        $fn = $this->handler;
        $response = $fn(new Context(
            $http,
            $api,
            $credentials,
            EventDecoder::fromInput($event)
        ));

        if (!empty($response)) {
            echo json_encode($response, JSON_THROW_ON_ERROR);
        }

        $this->endTracing($http);
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

    private function startTracing(): void {
        [$repository, $shop] = explode('_', $_ENV['AWS_LAMBDA_FUNCTION_NAME']);
        Trace::getInstance()
            ->setTraceHeader($_ENV['_X_AMZN_TRACE_ID'] ?? null)
            ->setName($repository)
            ->setUrl($shop)
            ->begin(100);
    }

    private function endTracing(TraceableHttpClient $httpClient): void {
        [$host, $port] = explode(':', $_ENV['AWS_XRAY_DAEMON_ADDRESS']);

        $instance = Trace::getInstance();
        foreach ($httpClient->getTracedRequests() as $request) {
            $segment = new HttpSegment();
            $segment
                ->setUrl($request['url'])
                ->setMethod($request['method'])
                ->setResponseCode($request['info']['http_code'])
                ->setStartEnd($request['info']['start_time'], $request['info']['start_time'] + $request['info']['total_time']);
            $instance->addSubsegment($segment);
        }

        $instance
            ->end()
            ->setResponseCode(200)
            ->submit(new DaemonSegmentSubmitter($host, (int) $port));
    }
}
