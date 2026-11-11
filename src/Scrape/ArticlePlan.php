<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

/**
 * The articles a run of one source will import, and why the others are not in it.
 */
final readonly class ArticlePlan
{
    /**
     * @param list<SitemapEntry> $entries        newest first
     * @param int                $upToDate       articles skipped because they are already imported from the listed version
     * @param int                $notEligible    URLs the sitemap listed that are not articles, or are older than the cutoff
     * @param int                $beyondLimit    articles left for a later run because of the per-run limit
     * @param int                $otherLanguages articles in the source's other languages; listed but not planned yet
     */
    public function __construct(
        public array $entries,
        public int $upToDate,
        public int $notEligible,
        public int $beyondLimit,
        public int $otherLanguages = 0,
    ) {}
}
