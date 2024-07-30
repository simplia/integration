<?php

namespace Simplia\Integration\Response;

class DownloadContentResponse implements Response {
    public function __construct(private readonly string $filename, private readonly string $content) {
    }

    public function getFilename(): string {
        return $this->filename;
    }

    public function getContent(): string {
        return $this->content;
    }

    public function jsonSerialize(): array {
        return [
            'download' => [
                'name' => $this->filename,
                'content' => base64_encode($this->content),
                'encoding' => 'base64',
            ],
        ];
    }
}
