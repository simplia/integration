<?php

namespace Simplia\Integration\Event\Shipment;

use Simplia\Integration\Event\IntegrationEvent;
use Simplia\Integration\Model\Shipment\Shipment;

class ShipmentNormalizeEvent implements IntegrationEvent {
    public function __construct(private readonly string $carrierCode, private readonly Shipment $shipment) {
    }

    public function getCarrierCode(): string {
        return $this->carrierCode;
    }

    public function getShipment(): Shipment {
        return $this->shipment;
    }
}
