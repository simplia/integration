<?php


namespace Simplia\Integration\Storage;

interface KeyValueStorage {
    public function get(string $key);

    public function set(string $key, $value): void;
}
