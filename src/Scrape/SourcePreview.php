<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

use DateTimeImmutable;

/**
 * Shows what a run of a source would do, without doing it: the same plan and the same importer as a real run, but
 * with a publisher that makes nothing and a store that remembers nothing. Pages are still fetched, because that is
 * the only way to know whether an article changed.
 */
final class SourcePreview
{
    /** The most articles one preview checks, so it finishes within a web request. */
    public const SAMPLE_SIZE = 20;

    public function __construct(
        private readonly ArticlePlanner $planner,
        private readonly ArticleImporter $importer,
        private readonly PreviewStore $store,
    ) {}

    /**
     * @throws FetchException|ScrapeException when the sitemap can not be fetched or read
     */
    public function run(SourceConfig $config, DateTimeImmutable $now): PreviewReport
    {
        $plan = $this->planner->plan($config, $now);
        $checked = array_slice($plan->entries, 0, self::SAMPLE_SIZE);

        $rows = [];
        foreach ($checked as $entry) {
            $outcome = $this->importer->import($entry);
            $error = $outcome === ImportOutcome::Failed ? $this->store->saved($entry->slug)?->error : null;
            $rows[] = new PreviewRow($entry, $outcome, $error);
        }

        return new PreviewReport($rows, count($plan->entries) - count($checked), $plan->upToDate, $plan->notEligible, $plan->beyondLimit);
    }
}
