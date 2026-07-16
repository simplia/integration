<?php

namespace Simplia\Integration\Tests;

use PHPUnit\Framework\TestCase;
use Simplia\Integration\Event\Export\ExportFile;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ExportFileTest extends TestCase {
    private function file(MockHttpClient $client): ExportFile {
        return new ExportFile(
            ['url' => 'https://s3.example/source', 'encoding' => 'gzip'],
            ['url' => 'https://s3.example/result', 'encoding' => 'gzip'],
            $client,
        );
    }

    public function testDownloadDecompressesToLocalFile(): void {
        $client = new MockHttpClient(new MockResponse(gzencode('<data/>')));

        $path = $this->file($client)->download();

        self::assertSame('<data/>', file_get_contents($path));
        unlink($path);
    }

    public function testGetContent(): void {
        $client = new MockHttpClient(new MockResponse(gzencode('<data/>')));

        self::assertSame('<data/>', $this->file($client)->getContent());
    }

    public function testUploadContentGzipsBody(): void {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = [$method, $url, $options['body']];

            return new MockResponse('');
        });

        $this->file($client)->uploadContent('<transformed/>');

        self::assertCount(1, $requests);
        [$method, $url, $body] = $requests[0];
        self::assertSame('PUT', $method);
        self::assertSame('https://s3.example/result', $url);
        self::assertSame('<transformed/>', gzdecode($body));
    }

    public function testUploadRoundTripThroughFile(): void {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = $options['body'];

            return new MockResponse('');
        });

        $path = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($path, '<transformed/>');
        $this->file($client)->upload($path);
        unlink($path);

        self::assertSame('<transformed/>', gzdecode($captured));
    }

    public function testUnsupportedSourceEncodingThrows(): void {
        $file = new ExportFile(
            ['url' => 'https://s3.example/source', 'encoding' => 'zip'],
            ['url' => 'https://s3.example/result', 'encoding' => 'gzip'],
            new MockHttpClient(),
        );

        $this->expectException(\RuntimeException::class);
        $file->download();
    }

    public function testFailedUploadThrows(): void {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 403]));

        $this->expectException(\RuntimeException::class);
        $this->file($client)->uploadContent('<x/>');
    }
}
