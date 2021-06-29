<?php

namespace Simplia\Integration;

use Simplia\Integration\Storage\KeyValueStorage;

class LockHandler {
    private KeyValueStorage $storage;
    private string $requestId;

    public function __construct(KeyValueStorage $storage) {
        $this->storage = $storage;
        $this->requestId = bin2hex(random_bytes(16));
    }

    private const KEY = '_lock';

    public function isLocked(): bool {
        $lock = $this->storage->get(self::KEY);
        if (!$lock) {
            return false;
        }
        if ($lock['request'] === $this->requestId) {
            return false;
        }

        return $lock['time'] > time();
    }

    public function lock(int $TTL): void {
        $this->storage->set(self::KEY, ['request' => $this->requestId, 'time' => time() + $TTL]);
    }

    public function unlock(): void {
        $this->storage->remove(self::KEY);
    }

}
