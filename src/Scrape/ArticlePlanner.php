<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

use DateTimeImmutable;

/**
 * Decides which articles of a source are worth importing in this run, so a run does not queue work that would do
 * nothing: the sitemap is read once, articles already imported from the listed version are left out, and the
 * newest come first. A per-run limit lets a large archive be imported over several runs.
 */
final class ArticlePlanner
{
    public function __construct(
        private readonly Fetcher $fetcher,
        private readonly SitemapReader $reader,
        private readonly ItemStateStore $store,
    ) {}

    /**
     * @throws FetchException|ScrapeException when the sitemap can not be fetched or read
     */
    public function plan(SourceConfig $config, DateTimeImmutable $now): ArticlePlan
    {
        $since = $config->sinceDays !== null ? $now->modify(sprintf('-%d days', $config->sinceDays)) : null;

        $sitemap = $this->reader->read($this->fetcher->get($config->sitemapUrl)->body, $config, $since);

        $candidates = [];
        $upToDate = 0;
        foreach ($sitemap->entries as $entry) {
            $state = $this->store->find($entry->slug);
            if ($state !== null && $state->isCurrentFor($entry)) {
                ++$upToDate;

                continue;
            }
            $candidates[] = $entry;
        }

        // Newest first; entries without a date go last, in sitemap order.
        usort($candidates, static function (SitemapEntry $a, SitemapEntry $b): int {
            return ($b->lastModified?->getTimestamp() ?? PHP_INT_MIN) <=> ($a->lastModified?->getTimestamp() ?? PHP_INT_MIN);
        });

        $limit = $config->maxPerRun;
        $entries = $limit !== null ? array_slice($candidates, 0, $limit) : $candidates;

        return new ArticlePlan($entries, $upToDate, count($sitemap->skipped), count($candidates) - count($entries));
    }
}
