<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Scrape;

use PHPUnit\Framework\TestCase;
use ContentImporterKit\Scrape\SourceConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use ContentImporterKit\Scrape\InvalidSourceException;

final class SourceConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function raw(array $overrides = []): array
    {
        return $overrides + [
            'newsUrl' => 'https://adferiad.org/cym/',
            'sitemapUrl' => 'https://adferiad.org/cym/post-sitemap.xml',
            'titleSelector' => 'h1',
            'articleSelector' => '.full_width.single_post .two_thirds',
            'bannerSelector' => '.page_banner_wrap .image_container .image_wrap',
            'sinceDays' => null,
        ];
    }

    public function testTheAdferiadSettingsAreValid(): void
    {
        $config = SourceConfig::fromArray(self::raw());

        self::assertSame('https://adferiad.org/cym/', $config->newsUrl);
        self::assertSame('h1', $config->titleSelector);
        self::assertNull($config->sinceDays);
    }

    public function testNewsUrlGetsATrailingSlash(): void
    {
        self::assertSame('https://adferiad.org/cym/', SourceConfig::fromArray(self::raw(['newsUrl' => 'https://adferiad.org/cym']))->newsUrl);
    }

    public function testMaxPerRunIsOptional(): void
    {
        self::assertNull(SourceConfig::fromArray(self::raw())->maxPerRun);
        self::assertSame(25, SourceConfig::fromArray(self::raw(['maxPerRun' => 25]))->maxPerRun);
        self::assertSame(50, SourceConfig::fromArray(self::raw(['maxPerRun' => '50']))->maxPerRun);
    }

    public function testNothingIsIgnoredByDefault(): void
    {
        $config = SourceConfig::fromArray(self::raw());

        self::assertSame([], $config->excludedSlugs);
        self::assertFalse($config->isExcluded('blog'));
    }

    public function testIgnoredSlugsCanBeGivenAsLinesOrAsAList(): void
    {
        $fromText = SourceConfig::fromArray(self::raw(['excludedSlugs' => "blog\n  Category-*  \r\n\n*-archive\nblog"]));
        $fromList = SourceConfig::fromArray(self::raw(['excludedSlugs' => ['blog', 'category-*', '*-archive']]));

        self::assertSame(['blog', 'category-*', '*-archive'], $fromText->excludedSlugs, 'trimmed, lowercased, blank lines and duplicates dropped');
        self::assertSame(['blog', 'category-*', '*-archive'], $fromList->excludedSlugs);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function ignoreMatches(): iterable
    {
        yield 'exact' => ['blog', true];
        yield 'a longer slug is not the same' => ['blog-post', false];
        yield 'a shorter slug is not the same' => ['blo', false];
        yield 'prefix wildcard' => ['category-news', true];
        yield 'prefix wildcard needs the prefix' => ['mycategory-news', false];
        yield 'suffix wildcard' => ['2024-archive', true];
        yield 'wildcard may match nothing' => ['category-', true];
        yield 'wildcard in the middle' => ['news-2024-roundup', true];
        yield 'unrelated' => ['an-ordinary-article', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ignoreMatches')]
    public function testIgnoreListMatching(string $slug, bool $expected): void
    {
        $config = SourceConfig::fromArray(self::raw(['excludedSlugs' => "blog\ncategory-*\n*-archive\nnews-*-roundup"]));

        self::assertSame($expected, $config->isExcluded($slug));
    }

    public function testAWildcardDoesNotMatchOtherCharacters(): void
    {
        $config = SourceConfig::fromArray(self::raw(['excludedSlugs' => 'category-*']));

        // Slugs only contain lowercase letters, numbers and hyphens, and so does the wildcard.
        self::assertFalse($config->isExcluded('category-news/extra'));
        self::assertFalse($config->isExcluded('category-NEWS'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badIgnoreLists(): iterable
    {
        yield 'only a wildcard would ignore everything' => ['*'];
        yield 'only wildcards' => ["blog\n***"];
        yield 'a slash' => ['news/blog'];
        yield 'a space inside' => ['my blog'];
        yield 'a regex character' => ['blog.*'];
        yield 'an underscore' => ['my_blog'];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'not text' => [[['nested']]];
        yield 'a number' => [5];
        yield 'too many' => [implode("\n", array_map(static fn(int $i): string => 'slug-' . $i, range(1, 201)))];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badIgnoreLists')]
    public function testBadIgnoreListsAreRejected(mixed $value): void
    {
        $this->expectException(InvalidSourceException::class);

        SourceConfig::fromArray(self::raw(['excludedSlugs' => $value]));
    }

    public function testExactlyTheMaximumNumberOfEntriesIsAccepted(): void
    {
        $lines = implode("\n", array_map(static fn(int $i): string => 'slug-' . $i, range(1, 200)));

        self::assertCount(200, SourceConfig::fromArray(self::raw(['excludedSlugs' => $lines]))->excludedSlugs);
    }

    public function testBannerSelectorIsOptional(): void
    {
        self::assertNull(SourceConfig::fromArray(self::raw(['bannerSelector' => '  ']))->bannerSelector);
        self::assertNull(SourceConfig::fromArray(self::raw(['bannerSelector' => null]))->bannerSelector);
    }

    public function testSinceDaysAcceptsNumbersAndNumericStrings(): void
    {
        self::assertSame(7, SourceConfig::fromArray(self::raw(['sinceDays' => 7]))->sinceDays);
        self::assertSame(30, SourceConfig::fromArray(self::raw(['sinceDays' => '30']))->sinceDays);
        self::assertNull(SourceConfig::fromArray(self::raw(['sinceDays' => '']))->sinceDays);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalid(): iterable
    {
        yield 'missing news url' => [['newsUrl' => null]];
        yield 'news url not a string' => [['newsUrl' => ['x']]];
        yield 'news url ftp' => [['newsUrl' => 'ftp://adferiad.org/cym/']];
        yield 'news url javascript' => [['newsUrl' => 'javascript:alert(1)']];
        yield 'news url without host' => [['newsUrl' => 'https:///cym/']];
        yield 'news url with credentials' => [['newsUrl' => 'https://user:pw@adferiad.org/cym/']];
        yield 'news url with a space' => [['newsUrl' => 'https://adferiad.org/c ym/']];
        yield 'news url too long' => [['newsUrl' => 'https://adferiad.org/' . str_repeat('a', 2000)]];
        yield 'sitemap on another host' => [['sitemapUrl' => 'https://evil.example/post-sitemap.xml']];
        yield 'sitemap on a look-alike host' => [['sitemapUrl' => 'https://adferiad.org.evil.example/post-sitemap.xml']];
        yield 'missing title selector' => [['titleSelector' => '']];
        yield 'missing article selector' => [['articleSelector' => null]];
        yield 'pseudo class selector' => [['titleSelector' => 'h1:first-child']];
        yield 'xpath selector' => [['articleSelector' => '//div']];
        yield 'bad banner selector' => [['bannerSelector' => 'div ~ p']];
        yield 'since days zero' => [['sinceDays' => 0]];
        yield 'since days huge' => [['sinceDays' => 4000]];
        yield 'since days text' => [['sinceDays' => 'week']];
        yield 'since days negative text' => [['sinceDays' => '-3']];
        yield 'since days float' => [['sinceDays' => 1.5]];
        yield 'max per run zero' => [['maxPerRun' => 0]];
        yield 'max per run huge' => [['maxPerRun' => 1001]];
        yield 'max per run text' => [['maxPerRun' => 'all']];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalid')]
    public function testInvalidSettingsAreRejected(array $overrides): void
    {
        $this->expectException(InvalidSourceException::class);

        SourceConfig::fromArray(self::raw($overrides));
    }

    public function testMessagesNameTheFieldNotTheValue(): void
    {
        try {
            SourceConfig::fromArray(self::raw(['newsUrl' => 'javascript:alert("<script>")']));
            self::fail('Expected an InvalidSourceException.');
        } catch (InvalidSourceException $e) {
            self::assertStringContainsString('newsUrl', $e->getMessage());
            self::assertStringNotContainsString('script', $e->getMessage());
        }
    }
}
