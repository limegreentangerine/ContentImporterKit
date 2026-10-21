<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Scrape;

use Closure;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Handler\MockHandler;
use ContentImporterKit\Scrape\Fetcher;
use ContentImporterKit\Scrape\ItemState;
use ContentImporterKit\Import\Sanitiser;
use ContentImporterKit\Security\UrlGuard;
use ContentImporterKit\Scrape\PreviewStore;
use ContentImporterKit\Scrape\SourceConfig;
use ContentImporterKit\Scrape\SitemapReader;
use ContentImporterKit\Import\PublishTarget;
use ContentImporterKit\Scrape\ArticleParser;
use ContentImporterKit\Scrape\ImportOutcome;
use ContentImporterKit\Scrape\SourcePreview;
use ContentImporterKit\Scrape\ArticlePlanner;
use ContentImporterKit\Import\PreviewPublisher;
use ContentImporterKit\Scrape\ArticleImporter;
use ContentImporterKit\Tests\Scrape\Support\FakeStore;
use ContentImporterKit\Tests\Scrape\Support\FakeLogger;

final class SourcePreviewTest extends TestCase
{
    private const NOW = '2025-04-10T12:00:00+00:00';

    private FakeStore $store;

    private static function config(): SourceConfig
    {
        return SourceConfig::fromArray([
            'newsUrl' => 'https://adferiad.org/cym/',
            'sitemapUrl' => 'https://adferiad.org/cym/post-sitemap.xml',
            'titleSelector' => 'h1',
            'articleSelector' => '.full_width.single_post .two_thirds',
        ]);
    }

    /**
     * @param array<string, string> $articles slug => lastmod
     */
    private static function sitemap(array $articles): string
    {
        $urls = '';
        foreach ($articles as $slug => $lastmod) {
            $urls .= sprintf('<url><loc>https://adferiad.org/cym/%s/</loc><lastmod>%s</lastmod></url>', $slug, $lastmod);
        }

        return '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $urls . '</urlset>';
    }

    /**
     * @param list<Response> $responses the sitemap first, then one answer per article that is fetched
     */
    private function preview(array $responses): SourcePreview
    {
        $guard = new UrlGuard([80, 443], Closure::fromCallable(static fn(string $host): array => $host === 'adferiad.org' ? ['93.184.216.34'] : []));
        $fetcher = new Fetcher(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]), $guard);
        $previewStore = new PreviewStore($this->store);

        return new SourcePreview(
            new ArticlePlanner($fetcher, new SitemapReader(), $this->store),
            new ArticleImporter(
                $fetcher,
                new ArticleParser(),
                new Sanitiser(),
                new PreviewPublisher(),
                $previewStore,
                self::config(),
                new PublishTarget(1, 'page', 'full', 'Main'),
                new FakeLogger(),
            ),
            $previewStore,
        );
    }

    private static function html(): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/article.html');
    }

    protected function setUp(): void
    {
        $this->store = new FakeStore();
    }

    public function testANewArticleWouldBeCreatedAndNothingIsRemembered(): void
    {
        $report = $this->preview([
            new Response(200, [], self::sitemap(['one' => '2025-03-01T00:00:00+00:00'])),
            new Response(200, [], self::html()),
        ])->run(self::config(), new DateTimeImmutable(self::NOW));

        self::assertCount(1, $report->rows);
        self::assertSame('one', $report->rows[0]->entry->slug);
        self::assertSame(ImportOutcome::Imported, $report->rows[0]->outcome);
        self::assertNull($report->rows[0]->error);
        self::assertSame([], $this->store->items, 'a preview must not change what is remembered');
    }

    public function testAnImportedArticleThatChangedWouldBeUpdated(): void
    {
        $this->store->save(new ItemState('one', 'https://adferiad.org/cym/one/', 77, null, null, new DateTimeImmutable('2025-01-01T00:00:00+00:00'), 'old-hash', 'imported'));

        $report = $this->preview([
            new Response(200, [], self::sitemap(['one' => '2025-03-01T00:00:00+00:00'])),
            new Response(200, [], self::html()),
        ])->run(self::config(), new DateTimeImmutable(self::NOW));

        self::assertSame(ImportOutcome::Updated, $report->rows[0]->outcome);
        self::assertSame('old-hash', $this->store->items['one']->contentHash);
        self::assertSame('imported', $this->store->items['one']->status);
    }

    public function testAnArticleThatCanNotBeFetchedIsReportedWithItsReason(): void
    {
        $report = $this->preview([
            new Response(200, [], self::sitemap(['gone' => '2025-03-01T00:00:00+00:00'])),
            new Response(404, [], 'Not found'),
        ])->run(self::config(), new DateTimeImmutable(self::NOW));

        self::assertSame(ImportOutcome::Failed, $report->rows[0]->outcome);
        self::assertNotEmpty($report->rows[0]->error);
        self::assertSame([], $this->store->items, 'a failure is not recorded either');
    }

    public function testOnlyTheFirstArticlesAreChecked(): void
    {
        $articles = [];
        for ($i = 1; $i <= 25; ++$i) {
            $articles['article-' . $i] = sprintf('2025-03-%02dT00:00:00+00:00', $i);
        }
        $responses = [new Response(200, [], self::sitemap($articles))];
        for ($i = 0; $i < SourcePreview::SAMPLE_SIZE; ++$i) {
            $responses[] = new Response(200, [], self::html());
        }

        $report = $this->preview($responses)->run(self::config(), new DateTimeImmutable(self::NOW));

        self::assertCount(SourcePreview::SAMPLE_SIZE, $report->rows);
        self::assertSame('article-25', $report->rows[0]->entry->slug, 'newest first, like a real run');
        self::assertSame(5, $report->notChecked);
    }

    public function testThePlanTotalsAreReported(): void
    {
        $this->store->save(new ItemState('done', 'https://adferiad.org/cym/done/', 5, null, null, new DateTimeImmutable('2025-03-01T00:00:00+00:00'), 'h', 'imported'));

        $report = $this->preview([
            new Response(200, [], self::sitemap(['done' => '2025-03-01T00:00:00+00:00', 'fresh' => '2025-03-02T00:00:00+00:00'])),
            new Response(200, [], self::html()),
        ])->run(self::config(), new DateTimeImmutable(self::NOW));

        self::assertSame(1, $report->upToDate);
        self::assertCount(1, $report->rows);
    }
}
