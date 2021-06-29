<?php

namespace Simplia\Integration\Storage;

use RuntimeException;

class LocalKeyValueStorage implements KeyValueStorage {

    private string $directory;

    public function __construct(string $directory) {
        $this->directory = $directory;
    }

    private function getKeyPath(string $key): string {
        return $this->directory . DIRECTORY_SEPARATOR . md5($key) . '.json';
    }


    public function get(string $key) {
        if (!is_dir($this->directory)) {
            throw new RuntimeException('Missing key value storage directory');
        }
        if (file_exists($this->getKeyPath($key))) {
            return json_decode(file_get_contents($this->getKeyPath($key)), true, 512, JSON_THROW_ON_ERROR);
        }

        return null;
    }

    public function set(string $key, $value): void {
        if (!is_dir($this->directory)) {
            throw new RuntimeException('Missing key value storage directory');
        }
        file_put_contents($this->getKeyPath($key), json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function remove(string $key): void {
        if (file_exists($this->getKeyPath($key))) {
            unlink($this->getKeyPath($key));
        }
    }
}
