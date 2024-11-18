<?php

namespace Simplia\Integration\Model\Shipment;

class Parcel implements \JsonSerializable {
    /**
     * @param ParcelItem[] $items
     */
    public function __construct(private readonly int $index, private readonly array $items, private readonly ?float $weight, private readonly ?float $width, private readonly ?float $height, private readonly ?float $depth) {
    }

    public function getIndex(): int {
        return $this->index;
    }

    public function getItems(): array {
        return $this->items;
    }

    public function getWeight(): ?float {
        return $this->weight;
    }

    public function getWidth(): ?float {
        return $this->width;
    }

    public function getHeight(): ?float {
        return $this->height;
    }

    public function getDepth(): ?float {
        return $this->depth;
    }

    public static function fromJson(array $data): self {
        return new self(
            $data['index'],
            array_map(static fn(array $item) => ParcelItem::fromJson($item), $data['items']),
            $data['weight'] ?? null,
            $data['width'] ?? null,
            $data['height'] ?? null,
            $data['depth'] ?? null
        );
    }

    public function jsonSerialize(): array {
        return [
            'index' => $this->index,
            'items' => $this->items,
            'weight' => $this->weight,
            'width' => $this->width,
            'height' => $this->height,
            'depth' => $this->depth,
        ];
    }
}
