<?php

namespace Simplia\Integration\Tests;

use PHPUnit\Framework\TestCase;
use Simplia\Integration\Event\EventDecoder;
use Simplia\Integration\Event\Export\ExportTransformEvent;

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

    public function testUnknownTypeReturnsNull(): void {
        self::assertNull(EventDecoder::fromInput(['type' => 'does.not.exist']));
    }
}
