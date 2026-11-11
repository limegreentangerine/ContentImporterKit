<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

/**
 * What a run would do with one article.
 */
final readonly class PreviewRow
{
    /**
     * @param string|null $error             why the article would fail, when $outcome is ImportOutcome::Failed
     * @param int         $linksNow          translations already imported, which the page would be linked to
     * @param int         $translationsLater translations not imported yet; they link themselves when they arrive
     */
    public function __construct(
        public SitemapEntry $entry,
        public ImportOutcome $outcome,
        public ?string $error,
        public int $linksNow = 0,
        public int $translationsLater = 0,
    ) {}
}
