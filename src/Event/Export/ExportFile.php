<?php

namespace Simplia\Integration\Event\Export;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Handles the transfer of an export.transform payload: downloads the gzipped
 * source from a presigned URL, exposes it as a plain local file, and uploads
 * the transformed result back to a presigned destination URL.
 */
class ExportFile {
    private array $source;
    private array $destination;
    private ?HttpClientInterface $client;

    public function __construct(array $source, array $destination, ?HttpClientInterface $client = null) {
        $this->source = $source;
        $this->destination = $destination;
        $this->client = $client;
    }

    public function setHttpClient(HttpClientInterface $client): void {
        $this->client = $client;
    }

    private function getClient(): HttpClientInterface {
        return $this->client ??= HttpClient::create(['timeout' => 5 * 60]);
    }

    /**
     * Downloads the source export and returns the path of a local, decompressed temporary file.
     */
    public function download(): string {
        $compressedPath = $this->downloadRaw();
        $path = $this->createTempFile('export');
        $in = gzopen($compressedPath, 'rb');
        if ($in === false) {
            unlink($compressedPath);
            unlink($path);
            throw new \RuntimeException('Cannot open downloaded export');
        }
        $out = fopen($path, 'wb');
        if ($out === false) {
            gzclose($in);
            unlink($compressedPath);
            unlink($path);
            throw new \RuntimeException('Cannot open downloaded export');
        }
        $succeeded = false;
        try {
            while (!gzeof($in)) {
                $chunk = gzread($in, 512 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException('Failed to decompress export');
                }
                if (fwrite($out, $chunk) === false) {
                    throw new \RuntimeException('Failed to write temporary file');
                }
            }
            $succeeded = true;
        } finally {
            gzclose($in);
            fclose($out);
            unlink($compressedPath);
            if (!$succeeded) {
                unlink($path);
            }
        }

        return $path;
    }

    /**
     * Downloads the source export without decompressing it (still gzipped). For very large files.
     */
    public function downloadRaw(): string {
        if (($this->source['encoding'] ?? null) !== 'gzip') {
            throw new \RuntimeException('Unsupported source encoding');
        }
        $client = $this->getClient();
        $response = $client->request('GET', $this->source['url']);
        $path = $this->createTempFile('export-gz');
        $out = fopen($path, 'wb');
        if ($out === false) {
            unlink($path);
            throw new \RuntimeException('Cannot create temporary file');
        }
        $succeeded = false;
        try {
            foreach ($client->stream($response) as $chunk) {
                if (fwrite($out, $chunk->getContent()) === false) {
                    throw new \RuntimeException('Failed to write temporary file');
                }
            }
            $succeeded = true;
        } finally {
            fclose($out);
            if (!$succeeded) {
                unlink($path);
            }
        }

        return $path;
    }

    public function getContent(): string {
        $path = $this->download();
        $content = file_get_contents($path);
        unlink($path);

        return $content;
    }

    /**
     * Gzips the given local file and uploads it as the transformed result.
     */
    public function upload(string $path): void {
        $this->uploadContent(file_get_contents($path));
    }

    public function uploadContent(string $content): void {
        $this->uploadBody(gzencode($content, 6));
    }

    /**
     * Uploads an already-gzipped file without recompression. For very large files.
     */
    public function uploadRaw(string $compressedPath): void {
        $handle = fopen($compressedPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open compressed export for upload');
        }
        try {
            $this->uploadBody($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param string|resource $body
     */
    private function uploadBody($body): void {
        if (($this->destination['encoding'] ?? null) !== 'gzip') {
            throw new \RuntimeException('Unsupported destination encoding');
        }
        $statusCode = $this->getClient()->request('PUT', $this->destination['url'], [
            'body' => $body,
        ])->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException('Result upload failed: HTTP ' . $statusCode);
        }
    }

    private function createTempFile(string $prefix): string {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new \RuntimeException('Cannot create temporary file');
        }

        return $path;
    }
}
