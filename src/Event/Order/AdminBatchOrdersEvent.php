<?php

namespace Simplia\Integration\Event\Order;

use Simplia\Integration\Event\IntegrationEvent;

class AdminBatchOrdersEvent implements IntegrationEvent {
    public function __construct(private readonly array $ids) {
    }

    public function getIds(): array {
        return $this->ids;
    }

}
