<?php

namespace Simplia\Integration\Storage;

use AsyncAws\Core\Stream\ResultStream;

class StoredFile {
    private readonly string $path;
    private readonly \DateTimeImmutable $lastChange;
    private readonly string $hash;
    private readonly ResultStream $resultStream;

    public function __construct(string $path, \DateTimeImmutable $lastChange, string $hash, ResultStream $resultStream) {
        $this->path = $path;
        $this->lastChange = $lastChange;
        $this->hash = $hash;
        $this->resultStream = $resultStream;
    }

    public function getPath(): string {
        return $this->path;
    }

    public function getLastChange(): \DateTimeImmutable {
        return $this->lastChange;
    }

    public function getHash(): string {
        return $this->hash;
    }

    public function getResultStream(): ResultStream {
        return $this->resultStream;
    }

    public function saveTo(string $path): void {
        $fp = fopen($path, 'wb');
        foreach ($this->resultStream->getChunks() as $chunk) {
            fwrite($fp, $chunk);
        }
        fclose($fp);
    }
}
