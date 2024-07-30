<?php

namespace Simplia\Integration\Event\Order;

use Simplia\Integration\Event\IntegrationEvent;

abstract class AdminBatchEvent implements IntegrationEvent {
    public function __construct(private readonly array $ids, private readonly array $formData) {
    }

    public function getIds(): array {
        return $this->ids;
    }

    public function getFormData(): array {
        return $this->formData;
    }

}
