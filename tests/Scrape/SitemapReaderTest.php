<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Scrape;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ContentImporterKit\Scrape\SitemapEntry;
use ContentImporterKit\Scrape\SourceConfig;
use ContentImporterKit\Scrape\SitemapReader;
use ContentImporterKit\Scrape\ScrapeException;
use PHPUnit\Framework\Attributes\DataProvider;

final class SitemapReaderTest extends TestCase
{
    private static function config(): SourceConfig
    {
        return SourceConfig::fromArray([
            'newsUrl' => 'https://adferiad.org/cym/',
            'sitemapUrl' => 'https://adferiad.org/cym/post-sitemap.xml',
            'titleSelector' => 'h1',
            'articleSelector' => '.two_thirds',
        ]);
    }

    private static function fixture(): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/post-sitemap.xml');
    }

    /**
     * @param list<SitemapEntry> $entries
     *
     * @return list<string>
     */
    private static function slugs(array $entries): array
    {
        return array_map(static fn(SitemapEntry $entry): string => $entry->slug, $entries);
    }

    public function testArticlesAreFoundAndEverythingElseIsLeftOutWithAReason(): void
    {
        $result = (new SitemapReader())->read(self::fixture(), self::config());

        self::assertSame(
            ['adferiad-yn-cyhoeddi-ymgyrch', 'dated-permalink-post', 'old-post', 'no-lastmod', 'bad-date'],
            self::slugs($result->entries),
        );

        $reasons = $result->skipped;
        self::assertArrayHasKey('https://adferiad.org/cym/', $reasons);
        self::assertArrayHasKey('https://adferiad.org/cym/adferiad-yn-cyhoeddi-ymgyrch/?utm_source=x', $reasons);
        self::assertArrayHasKey('https://adferiad.org/cym/UPPER-Case', $reasons);
        self::assertArrayHasKey('https://adferiad.org/cym/%C5%B5yl-post', $reasons);
        self::assertArrayHasKey('https://adferiad.org/cym/a/b/c', $reasons);
        self::assertArrayHasKey('https://adferiad.org/cym/../../etc/passwd', $reasons);
        self::assertArrayHasKey('https://evil.example/cym/offsite-post', $reasons);
        self::assertArrayHasKey('https://adferiad.org/other/page', $reasons);
        self::assertArrayHasKey('https://adferiad.org.evil.example/cym/lookalike-host', $reasons);
        self::assertCount(9, $reasons);
    }

    public function testIgnoredSlugsAreLeftOutWithTheirReason(): void
    {
        $config = SourceConfig::fromArray([
            'newsUrl' => 'https://adferiad.org/cym/',
            'sitemapUrl' => 'https://adferiad.org/cym/post-sitemap.xml',
            'titleSelector' => 'h1',
            'articleSelector' => '.two_thirds',
            'excludedSlugs' => "old-post\nno-*",
        ]);

        $result = (new SitemapReader())->read(self::fixture(), $config);

        self::assertSame(['adferiad-yn-cyhoeddi-ymgyrch', 'dated-permalink-post', 'bad-date'], self::slugs($result->entries));
        self::assertSame("On the source's ignore list.", $result->skipped['https://adferiad.org/cym/old-post/']);
        self::assertSame("On the source's ignore list.", $result->skipped['https://adferiad.org/cym/no-lastmod']);
    }

    public function testOnlyTheIgnoredSlugsAreRemoved(): void
    {
        $xml = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . '<url><loc>https://adferiad.org/cym/blog/</loc></url><url><loc>https://adferiad.org/cym/real-post/</loc></url></urlset>';
        $config = SourceConfig::fromArray([
            'newsUrl' => 'https://adferiad.org/cym/',
            'sitemapUrl' => 'https://adferiad.org/cym/post-sitemap.xml',
            'titleSelector' => 'h1',
            'articleSelector' => '.two_thirds',
            'excludedSlugs' => 'blog',
        ]);

        self::assertSame(['real-post'], self::slugs((new SitemapReader())->read($xml, $config)->entries));
    }

    public function testTheUrlToFetchIsTheSitemapsOwnLoc(): void
    {
        $entries = (new SitemapReader())->read(self::fixture(), self::config())->entries;

        self::assertSame('https://adferiad.org/cym/adferiad-yn-cyhoeddi-ymgyrch', $entries[0]->url);
        self::assertSame('https://adferiad.org/cym/2024/11/05/dated-permalink-post/', $entries[1]->url);
    }

    public function testLastModifiedIsReadWhenValid(): void
    {
        $entries = (new SitemapReader())->read(self::fixture(), self::config())->entries;

        self::assertSame('2025-03-14', $entries[0]->lastModified?->format('Y-m-d'));
        self::assertNull($entries[3]->lastModified, 'no <lastmod>');
        self::assertNull($entries[4]->lastModified, 'unparseable <lastmod>');
    }

    public function testEntriesAtOrBeforeTheCutoffAreLeftOut(): void
    {
        $result = (new SitemapReader())->read(self::fixture(), self::config(), new DateTimeImmutable('2024-11-05T09:00:00+00:00'));

        // dated-permalink-post is exactly on the cutoff and old-post is before it. Entries without a date are kept.
        self::assertSame(['adferiad-yn-cyhoeddi-ymgyrch', 'no-lastmod', 'bad-date'], self::slugs($result->entries));
        self::assertSame('Not modified since the cutoff.', $result->skipped['https://adferiad.org/cym/old-post/']);
    }

    public function testDateBasedPermalinksLoseTheirDatePrefix(): void
    {
        $entries = (new SitemapReader())->read(self::fixture(), self::config())->entries;

        self::assertSame('dated-permalink-post', $entries[1]->slug);
    }

    public function testASitemapIndexIsExplained(): void
    {
        $xml = '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://adferiad.org/a.xml</loc></sitemap></sitemapindex>';

        $this->expectException(ScrapeException::class);
        $this->expectExceptionMessageMatches('/sitemap index/');

        (new SitemapReader())->read($xml, self::config());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusable(): iterable
    {
        yield 'empty' => [''];
        yield 'not xml' => ['this is not xml'];
        yield 'html page' => ['<html><body>Not found</body></html>'];
        yield 'broken xml' => ['<urlset><url><loc>x</url></urlset>'];
        yield 'doctype' => ['<?xml version="1.0"?><!DOCTYPE urlset [<!ENTITY a "aaaa">]><urlset><url><loc>https://adferiad.org/cym/&a;</loc></url></urlset>'];
        yield 'external entity' => ['<?xml version="1.0"?><!DOCTYPE urlset [<!ENTITY x SYSTEM "file:///etc/passwd">]><urlset><url><loc>&x;</loc></url></urlset>'];
        yield 'lowercase doctype' => ['<?xml version="1.0"?><!doctype urlset><urlset></urlset>'];
    }

    #[DataProvider('unusable')]
    public function testUnusableSitemapsThrow(string $xml): void
    {
        $this->expectException(ScrapeException::class);

        (new SitemapReader())->read($xml, self::config());
    }

    public function testTooManyUrlsThrow(): void
    {
        $urls = str_repeat('<url><loc>https://adferiad.org/cym/post</loc></url>', SitemapReader::MAX_URLS + 1);
        $xml = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $urls . '</urlset>';

        $this->expectException(ScrapeException::class);

        (new SitemapReader())->read($xml, self::config());
    }

    public function testSitemapsWithoutANamespaceAreStillRead(): void
    {
        $xml = '<?xml version="1.0"?><urlset><url><loc>https://adferiad.org/cym/plain-post</loc></url></urlset>';

        self::assertSame(['plain-post'], self::slugs((new SitemapReader())->read($xml, self::config())->entries));
    }
}
