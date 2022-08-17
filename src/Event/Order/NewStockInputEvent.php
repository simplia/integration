<?php

namespace Simplia\Integration\Event\Order;

use Simplia\Integration\Event\IntegrationEvent;

class NewStockInputEvent implements IntegrationEvent {
    private string $id;

    public function __construct(string $id) {
        $this->id = $id;
    }

    public function getId(): string {
        return $this->id;
    }

}
