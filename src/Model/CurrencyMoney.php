<?php

namespace Simplia\Integration\Model;

class CurrencyMoney implements \JsonSerializable {
    public function __construct(private readonly string $localCurrencyCode, private readonly string $localValue, private readonly string $foreignCurrencyCode, private readonly string $foreignValue) {
    }

    public function getLocalCurrencyCode(): string {
        return $this->localCurrencyCode;
    }

    public function getLocalValue(): string {
        return $this->localValue;
    }

    public function getForeignCurrencyCode(): string {
        return $this->foreignCurrencyCode;
    }

    public function getForeignValue(): string {
        return $this->foreignValue;
    }

    public static function fromJson(array $data): self {
        return new self(
            $data['localCurrencyCode'],
            $data['localValue'],
            $data['foreignCurrencyCode'],
            $data['foreignValue']
        );
    }

    public function jsonSerialize(): array {
        return [
            'localCurrencyCode' => $this->localCurrencyCode,
            'localValue' => $this->localValue,
            'foreignCurrencyCode' => $this->foreignCurrencyCode,
            'foreignValue' => $this->foreignValue,
        ];
    }
}
