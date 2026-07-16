<?php

namespace Simplia\Integration\Event\Export;

use Simplia\Integration\Event\IntegrationEvent;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ExportTransformEvent implements IntegrationEvent {
    private ExportFile $file;

    public function __construct(
        private readonly string $target,
        array $source,
        array $destination,
        private readonly array $context = [],
    ) {
        $this->file = new ExportFile($source, $destination);
    }

    public function getTarget(): string {
        return $this->target;
    }

    public function getContext(): array {
        return $this->context;
    }

    public function getFile(): ExportFile {
        return $this->file;
    }

    public function setHttpClient(HttpClientInterface $client): void {
        $this->file->setHttpClient($client);
    }
}
