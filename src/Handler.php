<?php

namespace Simplia\Integration;

use AsyncAws\Ssm\SsmClient;
use Bref\Context\Context as BrefContext;
use \Bref\Event\Handler as BrefHandler;
use GuzzleHttp\Client;
use Pkerrigan\Xray\Submission\DaemonSegmentSubmitter;
use Pkerrigan\Xray\Trace;
use Simplia\Api\Api;
use Simplia\Integration\Event\EventDecoder;

class Handler implements BrefHandler {

    /** @var callable */
    private $handler;

    public function __construct(callable $handler) {
        $this->handler = $handler;
    }

    public function handle($event, BrefContext $context) {
        echo json_encode($event) . "\n";
        $this->startTracing($context);
        $http = new Client(['headers' => ['Accept-Encoding' => 'gzip']]);
        $credentials = $this->getCredentialsData();
        $api = Api::withUsernameAuth($credentials['shop']['host'], $credentials['shop']['user'], $credentials['shop']['password']);
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

        $this->endTracing();
    }

    private function getCredentialsData(): array {
        if ($_ENV['CREDENTIALS_PARAMETER_PATH']) {
            $ssm = new SsmClient([
                'region' => $_ENV['AWS_DEFAULT_REGION'],
            ]);
            $credentialsContent = $ssm->getParameter([
                'Name' => $_ENV['CREDENTIALS_PARAMETER_PATH'],
                'WithDecryption' => true,
            ])->getParameter()->getValue();
        } else {
            $credentialsContent = file_get_contents(__DIR__ . '/../../../.credentials.json');
        }

        return json_decode($credentialsContent, true, 512, JSON_THROW_ON_ERROR);
    }

    private function startTracing(BrefContext $context): void {
        [$repository, $shop] = explode('_', $_ENV['AWS_LAMBDA_FUNCTION_NAME']);
        Trace::getInstance()
            ->setTraceHeader($context->getTraceId())
            ->setName($repository)
            ->setUrl($shop)
            ->begin(100);
    }

    private function endTracing(): void {
        [$host, $port] = explode(':', $_ENV['AWS_XRAY_DAEMON_ADDRESS']);

        Trace::getInstance()
            ->getCurrentSegment()
            ->end()
            //->setResponseCode(200)
            ->submit(new DaemonSegmentSubmitter($host, (int) $port));
    }
}
