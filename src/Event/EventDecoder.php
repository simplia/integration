<?php

namespace Simplia\Integration\Event;

use Simplia\Integration\Event\Order\NewOrderEvent;
use Simplia\Integration\Event\Order\NewStockInputEvent;

class EventDecoder {
    public static function fromInput(array $input): ?IntegrationEvent {
        if (isset($input['Records'])) {
            $input = json_decode($input['Records'][0]['body'], true, 512, JSON_THROW_ON_ERROR);
        }

        $type = $input['type'] ?? null;
        if ((!$type)) {
            return null;
        }

        switch ($type) {
            case 'order.new' :
                return new NewOrderEvent($input['id']);
            case 'stock-input.new' :
                return new NewStockInputEvent($input['id']);
        }

        return null;
    }
}
