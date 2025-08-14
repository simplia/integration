<?php


namespace Simplia\Integration\Storage;

interface FileStorage {
    public function download(string $path): StoredFile;

    public function remove(string $path): void;

    public function uploadString(string $path, string $value): void;

    public function uploadFile(string $path, string $filePath): void;
}
