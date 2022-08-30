<?php

namespace Simplia\Integration\Event\Stock;

use Simplia\Integration\Event\IntegrationEvent;

class NewStockInputSupplierEvent implements IntegrationEvent {
    private string $id;

    public function __construct(string $id) {
        $this->id = $id;
    }

    public function getId(): string {
        return $this->id;
    }

}
