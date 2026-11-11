<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

use DateTimeImmutable;

/**
 * One article URL from a sitemap.
 */
final readonly class SitemapEntry
{
    /**
     * @param string|null  $hreflang   the language of the article, or null for the source's own language
     * @param list<string> $alternates the other-language versions of this article that the sitemap lists and that are
     *                                 under one of the source's news URLs; only kept for a source with several languages
     */
    public function __construct(
        public string $url,
        public string $slug,
        public ?DateTimeImmutable $lastModified,
        public ?string $hreflang = null,
        public array $alternates = [],
    ) {}
}
