<?php

namespace Simplia\Integration\Storage;

use AsyncAws\S3\S3Client;
use RuntimeException;

class RemoteFileStorage implements FileStorage {

    private readonly S3Client $s3Client;
    private readonly string $bucket;
    private readonly string $prefix;


    public function __construct(S3Client $s3Client, string $bucket, string $prefix) {
        $this->s3Client = $s3Client;
        $this->bucket = $bucket;
        $this->prefix = $prefix;
    }

    private function getPath(string $path): string {
        return $this->prefix . '/' . $path;
    }

    public function download(string $path): StoredFile {
        $result = $this->s3Client->getObject([
            'Bucket' => $this->bucket,
            'Key' => $this->getPath($path),
        ]);

        return new StoredFile(
            $path,
            $result->getLastModified(),
            trim($result->getEtag(), '"'),
            $result->getBody()
        );
    }

    public function remove(string $path): void {
        $this->s3Client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $this->getPath($path),
        ]);
    }

    public function uploadString(string $path, string $value): void {
        $this->s3Client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $this->getPath($path),
            'Body' => $value,
        ]);
    }

    public function uploadFile(string $path, string $filePath): void {
        $fp = fopen($filePath, 'rb');
        if ($fp === false) {
            throw new RuntimeException('Failed to open file');
        }
        $this->s3Client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $this->getPath($path),
            'Body' => $fp,
        ]);
    }
}
