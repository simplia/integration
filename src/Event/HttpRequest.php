<?php

namespace Simplia\Integration\Event;

class HttpRequest implements IntegrationEvent {
    /**
     * @param array<string, mixed> $query the request's query string (?web=reedog.cz → ['web' => 'reedog.cz'])
     */
    public function __construct(private readonly array $responseS3Destination, private readonly array $query = []) {
    }

    public function getResponseS3Destination(): array {
        return $this->responseS3Destination;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQuery(): array {
        return $this->query;
    }

    public function getQueryParameter(string $name): ?string {
        $value = $this->query[$name] ?? null;

        return is_string($value) ? $value : null;
    }

}
