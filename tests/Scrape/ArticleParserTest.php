<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Scrape;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ContentImporterKit\Import\Sanitiser;
use ContentImporterKit\Scrape\SourceConfig;
use ContentImporterKit\Import\ArticleRecord;
use ContentImporterKit\Scrape\ArticleParser;
use ContentImporterKit\Scrape\ScrapeException;

final class ArticleParserTest extends TestCase
{
    private const URL = 'https://adferiad.org/cym/adferiad-yn-cyhoeddi-ymgyrch/';

    private static function config(?string $banner = '.page_banner_wrap .image_container .image_wrap'): SourceConfig
    {
        return SourceConfig::fromArray([
            'newsUrl' => 'https://adferiad.org/cym/',
            'sitemapUrl' => 'https://adferiad.org/cym/post-sitemap.xml',
            'titleSelector' => 'h1',
            'articleSelector' => '.full_width.single_post .two_thirds',
            'bannerSelector' => $banner,
        ]);
    }

    private static function html(): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/article.html');
    }

    /**
     * @return array<string, mixed>
     */
    private static function parse(?string $html = null, ?SourceConfig $config = null, ?DateTimeImmutable $fallback = null): array
    {
        return (new ArticleParser())->parse(
            $html ?? self::html(),
            self::URL,
            'adferiad-yn-cyhoeddi-ymgyrch',
            $config ?? self::config(),
            $fallback,
        );
    }

    public function testTheRecordHasTheScrapersShape(): void
    {
        $record = self::parse();

        self::assertSame(
            ['title', 'published_date', 'meta_description', 'meta_keywords', 'description', 'body_html', 'urlSlug', 'attributes'],
            array_keys($record),
        );
        self::assertSame('adferiad-yn-cyhoeddi-ymgyrch', $record['urlSlug']);
        self::assertSame('content', $record['body_html'][0]['block_type']);
    }

    public function testTitleAndMetaAreExtractedAndTidied(): void
    {
        $record = self::parse();

        self::assertSame('Adferiad yn Cyhoeddi Ymgyrch Gwrth Stigma', $record['title']);
        self::assertSame('2025-03-14T16:11:16+00:00', $record['published_date']);
        self::assertSame('Mae Adferiad yn falch i gyhoeddi ymgyrch newydd.', $record['meta_description']);
        self::assertSame('adferiad, ymgyrch', $record['meta_keywords']);
    }

    public function testDescriptionIsTheFirstParagraphOfTheArticleNotTheSidebar(): void
    {
        self::assertSame('Mae Ŵyl y Cyfeillgarwch yn dechrau heddiw.', self::parse()['description']);
    }

    public function testWelshCharactersSurvive(): void
    {
        self::assertStringContainsString('<strong>Ŵyl y Cyfeillgarwch</strong>', self::parse()['body_html'][0]['data']);
    }

    public function testOnlyTheArticleBodyIsReturned(): void
    {
        $body = self::parse()['body_html'][0]['data'];

        self::assertStringNotContainsString('Sidebar text', $body);
        self::assertStringNotContainsString('<h1', $body);
    }

    public function testLinksAndImagesBecomeAbsolute(): void
    {
        $body = self::parse()['body_html'][0]['data'];

        self::assertStringContainsString('href="https://adferiad.org/cym/cysylltu"', $body);
        self::assertStringContainsString('href="https://adferiad.org/cym/archif/"', $body);
        self::assertStringContainsString('href="https://example.org/abs"', $body);
        self::assertStringContainsString('src="https://adferiad.org/wp-content/uploads/content.png"', $body);
        self::assertStringContainsString('src="https://cdn.example.org/proto.png"', $body);
    }

    public function testLazyLoadedImagesUseTheirRealAddress(): void
    {
        self::assertStringContainsString('src="https://adferiad.org/wp-content/uploads/lazy.jpg"', self::parse()['body_html'][0]['data']);
    }

    public function testMailtoAndFragmentLinksAreLeftAlone(): void
    {
        $body = self::parse()['body_html'][0]['data'];

        self::assertStringContainsString('href="mailto:post@adferiad.org"', $body);
        self::assertStringContainsString('href="#nodyn"', $body);
    }

    public function testUnusableImageSourcesAreRemoved(): void
    {
        self::assertStringNotContainsString('javascript:alert(2)', self::parse()['body_html'][0]['data']);
    }

    public function testBannerComesFromTheBannerSelector(): void
    {
        self::assertSame('https://adferiad.org/wp-content/uploads/banner.png', self::parse()['attributes']['banner']);
    }

    public function testBannerFallsBackToTheFirstImageInTheArticle(): void
    {
        self::assertSame('https://adferiad.org/wp-content/uploads/content.png', self::parse(null, self::config(null))['attributes']['banner']);
    }

    public function testBannerFallsBackWhenTheBannerElementHasNoImage(): void
    {
        $html = str_replace('<img src="/wp-content/uploads/banner.png" alt="Banner">', '', self::html());

        self::assertSame('https://adferiad.org/wp-content/uploads/content.png', self::parse($html)['attributes']['banner']);
    }

    public function testNoBannerIsNullNotAnError(): void
    {
        $html = '<h1>T</h1><div class="full_width single_post"><div class="two_thirds"><p>Text only</p></div></div>';

        self::assertNull(self::parse($html)['attributes']['banner']);
    }

    public function testPublishDateFallsBackToTheSitemapDate(): void
    {
        $html = (string) preg_replace('/<meta property="article:published_time"[^>]*>/', '', self::html());

        self::assertSame('2025-01-02T03:04:05+00:00', self::parse($html, null, new DateTimeImmutable('2025-01-02T03:04:05+00:00'))['published_date']);
    }

    public function testNoDateAtAllIsNullSoTheRecordIsRejectedLater(): void
    {
        $html = (string) preg_replace('/<meta property="article:published_time"[^>]*>/', '', self::html());

        self::assertNull(self::parse($html)['published_date']);
    }

    public function testAnInvalidMetaDateDoesNotCrash(): void
    {
        $html = str_replace('2025-03-14T16:11:16+00:00', 'not a date', self::html());

        self::assertSame('2025-06-01T00:00:00+00:00', self::parse($html, null, new DateTimeImmutable('2025-06-01T00:00:00+00:00'))['published_date']);
    }

    public function testOpenGraphDescriptionIsUsedWhenThereIsNoMetaDescription(): void
    {
        $html = (string) preg_replace('/<meta name="description"[^>]*>/', '', self::html());

        self::assertSame('Open graph description', self::parse($html)['meta_description']);
    }

    public function testDescriptionFallsBackToTheMetaDescriptionWhenThereIsNoParagraph(): void
    {
        $html = '<head><meta name="description" content="From meta"></head><h1>T</h1><div class="full_width single_post"><div class="two_thirds">Just text</div></div>';
        $record = self::parse($html);

        self::assertSame('From meta', $record['description']);
        self::assertSame('From meta', $record['meta_description']);
    }

    public function testMetaDescriptionFallsBackToTheDescription(): void
    {
        $html = '<h1>T</h1><div class="full_width single_post"><div class="two_thirds"><p>The first paragraph</p></div></div>';

        self::assertSame('The first paragraph', self::parse($html)['meta_description']);
    }

    public function testLongDescriptionsAreTruncated(): void
    {
        $html = '<h1>T</h1><div class="full_width single_post"><div class="two_thirds"><p>' . str_repeat('word ', 500) . '</p></div></div>';
        $description = self::parse($html)['description'];

        self::assertLessThanOrEqual(1000, mb_strlen($description));
        self::assertStringEndsWith('…', $description);
    }

    public function testAMissingTitleIsAnErrorForThatPage(): void
    {
        $this->expectException(ScrapeException::class);
        $this->expectExceptionMessageMatches('/title/');

        self::parse('<div class="full_width single_post"><div class="two_thirds"><p>x</p></div></div>');
    }

    public function testAnEmptyTitleIsAnErrorForThatPage(): void
    {
        $this->expectException(ScrapeException::class);

        self::parse('<h1>   </h1><div class="full_width single_post"><div class="two_thirds"><p>x</p></div></div>');
    }

    public function testMissingArticleContentIsAnErrorNotACrash(): void
    {
        $this->expectException(ScrapeException::class);
        $this->expectExceptionMessageMatches('/content/');

        self::parse('<h1>Title</h1><p>No article container</p>');
    }

    public function testNotHtmlAtAllIsAnErrorNotACrash(): void
    {
        $this->expectException(ScrapeException::class);

        self::parse('');
    }

    public function testTheParsedRecordPassesValidationAndTheSanitiserRemovesWhatIsDangerous(): void
    {
        $raw = self::parse();
        $record = ArticleRecord::fromArray($raw);

        self::assertSame('adferiad-yn-cyhoeddi-ymgyrch', $record->urlSlug);

        $clean = (new Sanitiser())->sanitise($record->bodyBlocks[0]['data']);

        self::assertStringContainsString('Ŵyl y Cyfeillgarwch', $clean);
        self::assertStringContainsString('href="https://adferiad.org/cym/cysylltu"', $clean);
        self::assertStringNotContainsString('<script', $clean);
        self::assertStringNotContainsString('alert(', $clean);
        self::assertStringNotContainsString('javascript:', $clean);
    }

    public function testTheBodyIsNotSanitisedByTheParserItself(): void
    {
        // Documented behaviour: sanitising is a separate, mandatory step in the import pipeline.
        self::assertStringContainsString('<script>', self::parse()['body_html'][0]['data']);
    }
}
