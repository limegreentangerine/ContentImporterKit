<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

/**
 * The article URLs found in a sitemap, and the URLs that were left out with the reason.
 */
final readonly class SitemapResult
{
    /**
     * @param list<SitemapEntry>    $entries in the order of the sitemap
     * @param array<string, string> $skipped reason per URL
     */
    public function __construct(
        public array $entries,
        public array $skipped,
    ) {}
}
