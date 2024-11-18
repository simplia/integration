<?php

namespace Simplia\Integration\Model\Shipment;

class ParcelItem implements \JsonSerializable {
    public function __construct(private readonly int $itemId, private readonly int $quantity) {
    }

    public function getItemId(): int {
        return $this->itemId;
    }

    public function getQuantity(): int {
        return $this->quantity;
    }

    public static function fromJson(array $data): self {
        return new self($data['itemId'], $data['quantity']);
    }

    public function jsonSerialize(): array {
        return [
            'itemId' => $this->itemId,
            'quantity' => $this->quantity,
        ];
    }
}
