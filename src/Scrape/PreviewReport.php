<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

/**
 * What a run of a source would do, for the articles that were checked.
 */
final readonly class PreviewReport
{
    /**
     * @param list<PreviewRow> $rows       the articles that were checked, newest first
     * @param int              $notChecked articles a real run would also consider, left out to keep the preview quick
     * @param int              $upToDate   articles already imported from the listed version
     * @param int              $notEligible URLs the sitemap listed that are not articles, or are older than the cutoff
     * @param int              $beyondLimit articles left for a later run because of the per-run limit
     */
    public function __construct(
        public array $rows,
        public int $notChecked,
        public int $upToDate,
        public int $notEligible,
        public int $beyondLimit,
    ) {}
}
