<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

use DateTimeImmutable;

/**
 * What is remembered about one article of one source between runs. The importer reads and updates it; an
 * ItemStateStore keeps it.
 */
final class ItemState
{
    public function __construct(
        public string $slug,
        public string $url,
        public ?int $pageID = null,
        public ?string $etag = null,
        public ?string $lastModified = null,
        public ?DateTimeImmutable $remoteLastmod = null,
        public ?string $contentHash = null,
        public string $status = 'new',
        public ?string $error = null,
    ) {}

    /**
     * Whether this article was imported from the very version of the page the sitemap lists now, so there is nothing
     * to fetch. False when it failed last time, when its page is not known, or when the sitemap has no date.
     */
    public function isCurrentFor(SitemapEntry $entry): bool
    {
        return $this->pageID !== null
            && $entry->lastModified !== null
            && $this->remoteLastmod !== null
            && $this->remoteLastmod->getTimestamp() === $entry->lastModified->getTimestamp()
            && in_array($this->status, ['imported', 'updated', 'unchanged'], true);
    }
}
