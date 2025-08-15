<?php

namespace Simplia\Integration\Event;

class Webhook implements IntegrationEvent {
    public function __construct(private readonly array $data) {
    }

    public function getData(): array {
        return $this->data;
    }

}
