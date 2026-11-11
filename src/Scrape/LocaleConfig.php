<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

/**
 * One extra language of a source: where its articles live on the site being scraped, and where they will be created
 * on this site. The source's own settings describe its main language, so only the other languages are listed.
 */
final readonly class LocaleConfig
{
    /**
     * @param string $hreflang     the language as the sitemap names it, lowercase, for example "en-gb"
     * @param string $locale       the Concrete locale of the section the pages go in, for example "en_GB"
     * @param int    $parentPageID the page the articles of this language are created under
     * @param string $newsUrl      articles of this language must be under this URL; always ends in a slash
     */
    public function __construct(
        public string $hreflang,
        public string $locale,
        public int $parentPageID,
        public string $newsUrl,
    ) {}
}
