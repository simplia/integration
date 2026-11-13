<?php

namespace Simplia\Integration\Tests;

use PHPUnit\Framework\TestCase;
use Simplia\Integration\Event\EventDecoder;
use Simplia\Integration\Event\Export\ExportTransformEvent;
use Simplia\Integration\Event\HttpRequest;
use Simplia\Integration\Event\Payment\NewPaymentEvent;

class EventDecoderTest extends TestCase {
    public function testDecodesExportTransform(): void {
        $event = EventDecoder::fromInput([
            'type' => 'export.transform',
            'target' => 'pohoda-invoice',
            'source' => ['url' => 'https://s3.example/source', 'encoding' => 'gzip'],
            'destination' => ['url' => 'https://s3.example/result', 'encoding' => 'gzip'],
            'context' => ['filename' => 'pohoda.xml'],
        ]);

        self::assertInstanceOf(ExportTransformEvent::class, $event);
        self::assertSame('pohoda-invoice', $event->getTarget());
        self::assertSame(['filename' => 'pohoda.xml'], $event->getContext());
    }

    public function testDecodesNewPaymentFromSqsRecord(): void {
        $event = EventDecoder::fromInput(['Records' => [['body' => json_encode([
            'type' => 'payment.new',
            'subtype' => null,
            'shop' => 'example',
            'id' => '12345',
            'data' => null,
        ])]]]);

        self::assertInstanceOf(NewPaymentEvent::class, $event);
        self::assertSame('12345', $event->getId());
    }

    public function testDecodesHttpRequestWithQuery(): void {
        $event = EventDecoder::fromInput([
            'type' => 'http.request',
            'query' => ['web' => 'reedog.cz', 'ids' => ['1', '2']],
            'responseS3Destination' => ['method' => 'PUT', 'url' => 'https://s3.example/result', 'headers' => []],
        ]);

        self::assertInstanceOf(HttpRequest::class, $event);
        self::assertSame('reedog.cz', $event->getQueryParameter('web'));
        self::assertNull($event->getQueryParameter('ids'));
        self::assertSame(['1', '2'], $event->getQuery()['ids']);
    }

    public function testDecodesHttpRequestFromAnOlderEshopWithoutQuery(): void {
        $event = EventDecoder::fromInput([
            'type' => 'http.request',
            'responseS3Destination' => ['method' => 'PUT', 'url' => 'https://s3.example/result', 'headers' => []],
        ]);

        self::assertInstanceOf(HttpRequest::class, $event);
        self::assertSame([], $event->getQuery());
        self::assertNull($event->getQueryParameter('web'));
    }

    public function testUnknownTypeReturnsNull(): void {
        self::assertNull(EventDecoder::fromInput(['type' => 'does.not.exist']));
    }
}
