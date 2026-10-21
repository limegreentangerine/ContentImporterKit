<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

use DateTimeImmutable;

/**
 * One article URL from a sitemap.
 */
final readonly class SitemapEntry
{
    public function __construct(
        public string $url,
        public string $slug,
        public ?DateTimeImmutable $lastModified,
    ) {}
}
