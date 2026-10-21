<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

/**
 * A fetched page. For a 304 answer to a conditional request the body is empty and $notModified is true.
 */
final readonly class FetchResult
{
    public function __construct(
        public string $url,
        public int $status,
        public string $body,
        public ?string $etag,
        public ?string $lastModified,
        public bool $notModified,
    ) {}
}
