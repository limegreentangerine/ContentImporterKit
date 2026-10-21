<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

use Throwable;
use DOMElement;
use DOMDocument;
use DateTimeImmutable;

/**
 * Reads the article URLs out of a sitemap.
 *
 * Differences from the original scraper: the URL to fetch is the sitemap's own <loc> (it was rebuilt from the slug),
 * only URLs on the source's host and under its news URL are accepted, and slugs must be plain lowercase words, so a
 * hostile sitemap can not produce a path that escapes the folder an article is saved in.
 */
final class SitemapReader
{
    public const MAX_URLS = 50000;

    private function childText(DOMElement $parent, string $name): string
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                return $child->textContent;
            }
        }

        return '';
    }

    private function slugFor(string $loc, string $newsUrl): ?string
    {
        $rest = substr($loc, strlen($newsUrl));
        $rest = substr($rest, 0, strcspn($rest, '?#'));
        $rest = trim($rest, '/');
        // WordPress date-based permalinks: 2025/03/14/my-post
        $rest = (string) preg_replace('#^\d{4}/\d{2}/\d{2}/#', '', $rest);

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $rest) === 1 ? $rest : null;
    }

    private function date(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param DateTimeImmutable|null $since entries last modified at or before this moment are left out; entries
     *                                      without a usable <lastmod> are kept
     *
     * @throws ScrapeException when the sitemap as a whole is unusable
     */
    public function read(string $xml, SourceConfig $config, ?DateTimeImmutable $since = null): SitemapResult
    {
        if (trim($xml) === '') {
            throw new ScrapeException('The sitemap is empty.');
        }

        // Sitemaps never need a DOCTYPE, and refusing it removes entity expansion tricks altogether.
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new ScrapeException('The sitemap contains a DOCTYPE, which is not allowed.');
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false || $document->documentElement === null) {
            throw new ScrapeException('The sitemap is not valid XML.');
        }
        if ($document->documentElement->localName === 'sitemapindex') {
            throw new ScrapeException('The URL is a sitemap index. Use the address of one of the sitemaps it lists.');
        }
        if ($document->documentElement->localName !== 'urlset') {
            throw new ScrapeException('The XML is not a sitemap.');
        }

        $urls = $document->getElementsByTagNameNS('*', 'url');
        if ($urls->length > self::MAX_URLS) {
            throw new ScrapeException('The sitemap contains too many URLs.');
        }

        $newsHost = strtolower((string) parse_url($config->newsUrl, PHP_URL_HOST));
        $entries = [];
        $skipped = [];
        $seen = [];

        foreach ($urls as $node) {
            $loc = trim($this->childText($node, 'loc'));
            if ($loc === '') {
                continue;
            }

            if ($loc === $config->newsUrl) {
                $skipped[$loc] = 'This is the news index page, not an article.';

                continue;
            }
            if (
                !str_starts_with($loc, $config->newsUrl)
                || strtolower((string) parse_url($loc, PHP_URL_HOST)) !== $newsHost
            ) {
                $skipped[$loc] = 'The URL is not under the news URL.';

                continue;
            }

            $slug = $this->slugFor($loc, $config->newsUrl);
            if ($slug === null) {
                $skipped[$loc] = 'The URL does not end in a usable slug.';

                continue;
            }
            if ($config->isExcluded($slug)) {
                $skipped[$loc] = 'On the source\'s ignore list.';

                continue;
            }
            if (isset($seen[$slug])) {
                $skipped[$loc] = 'Another URL in the sitemap has the same slug.';

                continue;
            }

            $lastModified = $this->date($this->childText($node, 'lastmod'));
            if ($since !== null && $lastModified !== null && $lastModified <= $since) {
                $skipped[$loc] = 'Not modified since the cutoff.';

                continue;
            }

            $seen[$slug] = true;
            $entries[] = new SitemapEntry($loc, $slug, $lastModified);
        }

        return new SitemapResult($entries, $skipped);
    }
}
