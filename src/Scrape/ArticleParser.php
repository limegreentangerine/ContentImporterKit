<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

use DOMNode;
use DOMXPath;
use Throwable;
use DOMElement;
use DOMDocument;
use DateTimeImmutable;

/**
 * Turns the HTML of one article page into the record shape the importer reads (the scraper's JSON format).
 *
 * The body is returned as found, with links and image addresses made absolute. It is NOT sanitised here: the import
 * pipeline runs every body through the Sanitiser before it is stored. A page that is missing the title or the article
 * content is an error for that page, where the original scraper crashed and stopped the whole run.
 */
final class ArticleParser
{
    private const MAX_DESCRIPTION = 1000;

    private SelectorTranslator $selectors;

    private UrlResolver $resolver;

    public function __construct(?SelectorTranslator $selectors = null, ?UrlResolver $resolver = null)
    {
        $this->selectors = $selectors ?? new SelectorTranslator();
        $this->resolver = $resolver ?? new UrlResolver();
    }

    private function load(string $html): DOMDocument
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The processing instruction makes libxml read the markup as UTF-8 whatever the page declares.
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function first(DOMXPath $xpath, string $selector): ?DOMElement
    {
        $nodes = $xpath->query($this->selectors->toXPath($selector));
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    /**
     * @return array{description: string, keywords: ?string, published: ?string}
     */
    private function meta(DOMXPath $xpath): array
    {
        $description = '';
        $openGraphDescription = '';
        $keywords = null;
        $published = null;

        foreach ($xpath->query('//meta[@content]') ?: [] as $meta) {
            if (!$meta instanceof DOMElement) {
                continue;
            }
            $property = strtolower($meta->getAttribute('property'));
            $name = strtolower($meta->getAttribute('name'));
            $content = $this->text($meta->getAttribute('content'));
            if ($content === '') {
                continue;
            }

            if ($name === 'description') {
                $description = $description !== '' ? $description : $content;
            } elseif ($property === 'og:description') {
                $openGraphDescription = $openGraphDescription !== '' ? $openGraphDescription : $content;
            } elseif ($name === 'keywords') {
                $keywords ??= $content;
            } elseif ($published === null && ($property === 'article:published_time' || $name === 'pubdate' || $name === 'date')) {
                $published = $this->date($content);
            }
        }

        return [
            'description' => $description !== '' ? $description : $openGraphDescription,
            'keywords' => $keywords,
            'published' => $published,
        ];
    }

    private function date(string $value): ?string
    {
        try {
            return (new DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
    }

    private function makeUrlsAbsolute(DOMXPath $xpath, DOMElement $content, string $pageUrl): void
    {
        foreach ($xpath->query('.//a[@href]', $content) ?: [] as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }
            $href = trim($link->getAttribute('href'));
            // Fragments and non-http schemes (mailto:, tel:) stay as they are; the Sanitiser decides what is allowed.
            if ($href === '' || $href[0] === '#' || $this->hasOtherScheme($href)) {
                continue;
            }
            $absolute = $this->resolver->resolve($pageUrl, $href);
            if ($absolute !== null) {
                $link->setAttribute('href', $absolute);
            }
        }

        foreach ($xpath->query('.//img', $content) ?: [] as $image) {
            if (!$image instanceof DOMElement) {
                continue;
            }
            $source = trim($image->getAttribute('src'));
            // Lazy-loading plugins leave a placeholder in src and the real address in data-src.
            if (($source === '' || str_starts_with(strtolower($source), 'data:')) && $image->getAttribute('data-src') !== '') {
                $source = trim($image->getAttribute('data-src'));
            }
            $absolute = $this->resolver->resolve($pageUrl, $source);
            if ($absolute !== null) {
                $image->setAttribute('src', $absolute);
            } else {
                $image->removeAttribute('src');
            }
        }
    }

    private function hasOtherScheme(string $reference): bool
    {
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.-]*):/', $reference, $match) !== 1) {
            return false;
        }

        return !in_array(strtolower($match[1]), ['http', 'https'], true);
    }

    private function banner(DOMXPath $xpath, DOMElement $content, SourceConfig $config, string $pageUrl): ?string
    {
        if ($config->bannerSelector !== null) {
            $banner = $this->first($xpath, $config->bannerSelector);
            if ($banner !== null) {
                $url = $this->firstImageUrl($xpath, $banner, $pageUrl);
                if ($url !== null) {
                    return $url;
                }
            }
        }

        return $this->firstImageUrl($xpath, $content, $pageUrl);
    }

    private function firstImageUrl(DOMXPath $xpath, DOMElement $within, string $pageUrl): ?string
    {
        foreach ($xpath->query('.//img', $within) ?: [] as $image) {
            if (!$image instanceof DOMElement) {
                continue;
            }
            $source = trim($image->getAttribute('src'));
            if ($source === '' || str_starts_with(strtolower($source), 'data:')) {
                $source = trim($image->getAttribute('data-src'));
            }
            $url = $this->resolver->resolve($pageUrl, $source);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function innerHtml(DOMDocument $document, DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return trim((string) preg_replace('/>\s{2,}</', '> <', $html));
    }

    private function text(string $value): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $value));
    }

    private function truncate(string $value): string
    {
        return mb_strlen($value) > self::MAX_DESCRIPTION
            ? rtrim(mb_substr($value, 0, self::MAX_DESCRIPTION - 1)) . '…'
            : $value;
    }

    /**
     * @param string                 $url          the address the page was fetched from, used to resolve relative links
     * @param DateTimeImmutable|null $fallbackDate used when the page carries no publish date (the sitemap's lastmod)
     *
     * @return array<string, mixed>
     *
     * @throws ScrapeException
     */
    public function parse(
        string $html,
        string $url,
        string $slug,
        SourceConfig $config,
        ?DateTimeImmutable $fallbackDate = null,
    ): array {
        $document = $this->load($html);
        $xpath = new DOMXPath($document);

        $titleNode = $this->first($xpath, $config->titleSelector);
        if ($titleNode === null) {
            throw new ScrapeException('The article title was not found with the configured selector.');
        }
        $title = $this->text($titleNode->textContent);
        if ($title === '') {
            throw new ScrapeException('The article title is empty.');
        }

        $content = $this->first($xpath, $config->articleSelector);
        if ($content === null) {
            throw new ScrapeException('The article content was not found with the configured selector.');
        }

        $meta = $this->meta($xpath);

        $firstParagraph = $xpath->query('.//p', $content)->item(0);
        $description = $firstParagraph !== null ? $this->text($firstParagraph->textContent) : '';
        if ($description === '') {
            $description = $meta['description'];
        }
        $description = $this->truncate($description);

        $this->makeUrlsAbsolute($xpath, $content, $url);

        return [
            'title' => $title,
            'published_date' => $meta['published'] ?? $fallbackDate?->format(DATE_ATOM),
            'meta_description' => $meta['description'] !== '' ? $this->truncate($meta['description']) : $description,
            'meta_keywords' => $meta['keywords'],
            'description' => $description,
            'body_html' => [['block_type' => 'content', 'data' => $this->innerHtml($document, $content)]],
            'urlSlug' => $slug,
            'attributes' => ['banner' => $this->banner($xpath, $content, $config, $url)],
        ];
    }
}
