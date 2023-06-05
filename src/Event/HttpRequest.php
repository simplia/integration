<?php

namespace Simplia\Integration\Event;

class HttpRequest implements IntegrationEvent {
    public function __construct(private readonly array $responseS3Destination) {
    }

    public function getResponseS3Destination(): array {
        return $this->responseS3Destination;
    }

}
