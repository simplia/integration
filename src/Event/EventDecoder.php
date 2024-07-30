<?php

namespace Simplia\Integration\Event;

use Simplia\Integration\Event\Order\AdminBatchOrdersEvent;
use Simplia\Integration\Event\Order\AdminBatchProductsEvent;
use Simplia\Integration\Event\Order\AdminBatchUsersEvent;
use Simplia\Integration\Event\Order\NewOrderEvent;
use Simplia\Integration\Event\Stock\NewStockInputSupplierEvent;

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
            case 'http.request' :
                return new HttpRequest($input['responseS3Destination']);
            case 'order.new' :
                return new NewOrderEvent($input['id']);
            case 'admin.order.batch' :
                return new AdminBatchOrdersEvent($input['ids'], $input['formData'] ?? []);
            case 'admin.product.batch' :
                return new AdminBatchProductsEvent($input['ids'], $input['formData'] ?? []);
            case 'admin.user.batch' :
                return new AdminBatchUsersEvent($input['ids'], $input['formData'] ?? []);
            case 'stock-input-supplier.new' :
                return new NewStockInputSupplierEvent($input['id']);
        }

        return null;
    }
}
