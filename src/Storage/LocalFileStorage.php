<?php

namespace Simplia\Integration\Storage;

use AsyncAws\Core\Test\SimpleResultStream;
use RuntimeException;

class LocalFileStorage implements FileStorage {

    private string $directory;

    public function __construct(string $directory) {
        $this->directory = $directory;
    }

    private function getPath(string $path): string {
        return $this->directory . DIRECTORY_SEPARATOR . $path;
    }

    public function download(string $path): StoredFile {
        if (!file_exists($this->getPath($path))) {
            throw new RuntimeException('File not found');
        }

        return new StoredFile(
            $path,
            \DateTimeImmutable::createFromFormat('U', (string) filemtime($this->directory . DIRECTORY_SEPARATOR . $path)),
            md5_file($this->getPath($path)),
            new SimpleResultStream(file_get_contents($this->getPath($path)))
        );
    }

    public function remove(string $path): void {
        unlink($this->getPath($path));
    }

    public function uploadString(string $path, string $value): void {
        if (file_put_contents($this->getPath($path), $value) === false) {
            throw new RuntimeException('Failed to write file');
        }
    }

    public function uploadFile(string $path, string $filePath): void {
        if (!copy($filePath, $this->getPath($path))) {
            throw new RuntimeException('Failed to copy file');
        }
    }
}
