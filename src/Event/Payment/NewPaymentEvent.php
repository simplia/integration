<?php

namespace Simplia\Integration\Event\Payment;

use Simplia\Integration\Event\IntegrationEvent;

/**
 * payment.new - a new payment (uhrada) was committed in the shop.
 *
 * Payload: {"type": "payment.new", "id": "<payment id>", "data": null}
 * The event carries only the id: load the current payment, with its order or document, through the
 * shop API (GET /api/3/payments/{id}). A payment deleted again since answers 404 - ignore the event.
 * Delivery is at least once - treat a repeated payment id as the same payment.
 */
class NewPaymentEvent implements IntegrationEvent {
    private string $id;

    public function __construct(string $id) {
        $this->id = $id;
    }

    /** Payment id (/api/3/payments/{id}). */
    public function getId(): string {
        return $this->id;
    }

}
