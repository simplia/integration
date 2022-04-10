<?php

namespace Simplia\Integration\Storage;

use RuntimeException;

class LocalKeyValueStorage implements KeyValueStorage {

    private string $file;

    public function __construct(string $file) {
        $this->file = $file;
    }

    private function getKeyPath(string $key): string {
        return $this->file . DIRECTORY_SEPARATOR . md5($key) . '.json';
    }

    public function get(string $key) {
        return $this->load()[$key] ?? null;
    }

    public function set(string $key, $value): void {
        $data = $this->load();
        $data[$key] = $value;
        $this->write($data);
    }

    public function remove(string $key): void {
        $data = $this->load();
        if (array_key_exists($key, $data)) {
            unset($data[$key]);
            $this->write($data);
        }
    }

    private function write(array $data): void {
        file_put_contents($this->file, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
    }

    private function load(): array {
        if (file_exists($this->file)) {
            return json_decode(file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR);
        }

        return [];
    }
}
