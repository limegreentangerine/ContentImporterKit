<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

/**
 * Turns the URLs found in scraped pages into absolute http(s) URLs (RFC 3986 reference resolution).
 *
 * Handles the cases the original scraper got wrong: protocol-relative URLs (//cdn.example.org/a.png) were treated as
 * relative paths, and "../" segments were never resolved.
 */
final class UrlResolver
{
    private function removeDotSegments(string $path): string
    {
        $output = [];
        $segments = explode('/', $path);
        $last = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if ($segment === '..') {
                if (count($output) > 1) {
                    array_pop($output);
                }
                if ($index === $last) {
                    $output[] = '';
                }
            } elseif ($segment === '.') {
                if ($index === $last) {
                    $output[] = '';
                }
            } else {
                $output[] = $segment;
            }
        }

        $result = implode('/', $output);

        return $result === '' || $result[0] !== '/' ? '/' . $result : $result;
    }
    /**
     * @return string|null an absolute http(s) URL, or null when the reference is empty or not an http(s) URL
     */
    public function resolve(string $base, string $reference): ?string
    {
        $reference = trim($reference);
        if ($reference === '' || preg_match('/[\x00-\x20\x7f]/', $reference) === 1) {
            return null;
        }

        $baseParts = parse_url($base);
        if ($baseParts === false || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }
        $baseScheme = strtolower($baseParts['scheme']);
        if ($baseScheme !== 'http' && $baseScheme !== 'https') {
            return null;
        }

        // An absolute reference: only http(s) is acceptable.
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.-]*):/', $reference, $scheme) === 1) {
            $scheme = strtolower($scheme[1]);

            return ($scheme === 'http' || $scheme === 'https') && parse_url($reference, PHP_URL_HOST) ? $reference : null;
        }

        $authority = $baseScheme . '://' . $baseParts['host'] . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');

        if (str_starts_with($reference, '//')) {
            $url = $baseScheme . ':' . $reference;

            return parse_url($url, PHP_URL_HOST) ? $url : null;
        }

        $basePath = $baseParts['path'] ?? '/';
        $baseQuery = isset($baseParts['query']) ? '?' . $baseParts['query'] : '';

        if ($reference[0] === '#') {
            return $authority . $basePath . $baseQuery . $reference;
        }
        if ($reference[0] === '?') {
            return $authority . $basePath . $reference;
        }

        // Split the reference into path and the query/fragment tail, which resolution never changes.
        $tailStart = strcspn($reference, '?#');
        $path = substr($reference, 0, $tailStart);
        $tail = substr($reference, $tailStart);

        if ($path[0] !== '/') {
            $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
            $path = $directory . $path;
        }

        return $authority . $this->removeDotSegments($path) . $tail;
    }
}
