<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

/**
 * What a run would do with one article.
 */
final readonly class PreviewRow
{
    /**
     * @param string|null $error why the article would fail, when $outcome is ImportOutcome::Failed
     */
    public function __construct(
        public SitemapEntry $entry,
        public ImportOutcome $outcome,
        public ?string $error,
    ) {}
}
