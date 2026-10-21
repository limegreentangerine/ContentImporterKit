<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

/**
 * The settings that describe one site to scrape. Replaces the scraper's config.json.
 *
 * The values are validated for shape only. Whether a URL may actually be fetched is decided by UrlGuard, at the time
 * of every request.
 */
final readonly class SourceConfig
{
    private const MAX_EXCLUDED = 200;

    /**
     * @param list<string> $excludedSlugs article slugs the source ignores; "*" matches any run of letters, numbers
     *                                    and hyphens
     */
    public function __construct(
        public string $newsUrl,
        public string $sitemapUrl,
        public string $titleSelector,
        public string $articleSelector,
        public ?string $bannerSelector,
        public ?int $sinceDays,
        public ?int $maxPerRun = null,
        public array $excludedSlugs = [],
    ) {}

    /**
     * @return list<string>
     *
     * @throws InvalidSourceException
     */
    private static function excludedSlugs(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $lines = is_string($value) ? preg_split('/\R/', $value) : $value;
        if (!is_array($lines)) {
            throw new InvalidSourceException('"excludedSlugs" must be a list.');
        }

        $slugs = [];
        foreach ($lines as $line) {
            if (!is_string($line)) {
                throw new InvalidSourceException('"excludedSlugs" must be a list of text.');
            }
            $slug = strtolower(trim($line));
            if ($slug === '') {
                continue;
            }
            // A slug of only wildcards would ignore every article, which is never what is meant.
            if (preg_match('/^[a-z0-9*-]{1,128}$/', $slug) !== 1 || trim($slug, '*') === '') {
                throw new InvalidSourceException('"excludedSlugs" may only contain lowercase letters, numbers, hyphens and * (with at least one other character).');
            }
            $slugs[$slug] = true;
        }
        if (count($slugs) > self::MAX_EXCLUDED) {
            throw new InvalidSourceException('"excludedSlugs" has too many entries.');
        }

        return array_keys($slugs);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function url(array $raw, string $field): string
    {
        $value = $raw[$field] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > 2000) {
            throw new InvalidSourceException(sprintf('"%s" is required.', $field));
        }
        $value = trim($value);

        $parts = parse_url($value);
        if (
            $parts === false
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1
        ) {
            throw new InvalidSourceException(sprintf('"%s" must be an http or https URL.', $field));
        }

        return $value;
    }

    private static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function selector(array $raw, string $field): string
    {
        $value = $raw[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidSourceException(sprintf('"%s" is required.', $field));
        }

        return self::checkedSelector(trim($value), $field);
    }

    private static function checkedSelector(string $selector, string $field): string
    {
        try {
            (new SelectorTranslator())->toXPath($selector);
        } catch (InvalidSelectorException) {
            throw new InvalidSourceException(sprintf('"%s" is not a supported selector.', $field));
        }

        return $selector;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function optionalInt(array $raw, string $field, int $min, int $max): ?int
    {
        $value = $raw[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidSourceException(sprintf('"%s" must be a whole number.', $field));
        }
        $value = (int) $value;
        if ($value < $min || $value > $max) {
            throw new InvalidSourceException(sprintf('"%s" must be between %d and %d.', $field, $min, $max));
        }

        return $value;
    }

    /**
     * Whether an article is on the source's ignore list, for pages under the news URL that are not articles (an
     * index, a category) but that the sitemap lists as if they were.
     */
    public function isExcluded(string $slug): bool
    {
        foreach ($this->excludedSlugs as $pattern) {
            $regex = '/^' . str_replace('\*', '[a-z0-9-]*', preg_quote($pattern, '/')) . '$/';
            if (preg_match($regex, $slug) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $raw keys: newsUrl, sitemapUrl, titleSelector, articleSelector, bannerSelector, sinceDays
     *
     * @throws InvalidSourceException
     */
    public static function fromArray(array $raw): self
    {
        $newsUrl = self::url($raw, 'newsUrl');
        // Every article URL must start with this prefix, so "https://example.org/news" must not match "/newsletter".
        $newsUrl = str_ends_with($newsUrl, '/') ? $newsUrl : $newsUrl . '/';
        $sitemapUrl = self::url($raw, 'sitemapUrl');

        if (self::host($newsUrl) !== self::host($sitemapUrl)) {
            throw new InvalidSourceException('"sitemapUrl" must be on the same host as "newsUrl".');
        }

        $banner = $raw['bannerSelector'] ?? null;
        $banner = is_string($banner) && trim($banner) !== '' ? trim($banner) : null;

        return new self(
            $newsUrl,
            $sitemapUrl,
            self::selector($raw, 'titleSelector'),
            self::selector($raw, 'articleSelector'),
            $banner === null ? null : self::checkedSelector($banner, 'bannerSelector'),
            self::optionalInt($raw, 'sinceDays', 1, 3650),
            self::optionalInt($raw, 'maxPerRun', 1, 1000),
            self::excludedSlugs($raw['excludedSlugs'] ?? null),
        );
    }
}
