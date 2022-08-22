<?php

namespace Simplia\Integration\Event\Document;

use Simplia\Integration\Event\IntegrationEvent;

class NewDocumentEvent implements IntegrationEvent {
    private string $id;

    public function __construct(string $id) {
        $this->id = $id;
    }

    public function getId(): string {
        return $this->id;
    }
}
