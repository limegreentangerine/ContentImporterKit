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
use ContentImporterKit\Security\UrlGuard;
use ContentImporterKit\Scrape\SitemapEntry;
use ContentImporterKit\Scrape\SourceConfig;
use ContentImporterKit\Scrape\SitemapReader;
use ContentImporterKit\Scrape\ArticlePlanner;
use ContentImporterKit\Scrape\FetchException;
use ContentImporterKit\Scrape\ScrapeException;
use ContentImporterKit\Tests\Scrape\Support\FakeStore;

final class ArticlePlannerTest extends TestCase
{
    private const NOW = '2025-04-10T12:00:00+00:00';

    private FakeStore $store;

    /**
     * @param array<string, mixed> $overrides
     */
    private static function config(array $overrides = []): SourceConfig
    {
        return SourceConfig::fromArray($overrides + [
            'newsUrl' => 'https://adferiad.org/cym/',
            'sitemapUrl' => 'https://adferiad.org/cym/post-sitemap.xml',
            'titleSelector' => 'h1',
            'articleSelector' => '.two_thirds',
        ]);
    }

    /**
     * @param array<string, string|null> $articles slug => lastmod
     */
    private static function sitemap(array $articles): string
    {
        $urls = '<url><loc>https://adferiad.org/cym/</loc></url>';
        foreach ($articles as $slug => $lastmod) {
            $urls .= sprintf('<url><loc>https://adferiad.org/cym/%s/</loc>%s</url>', $slug, $lastmod === null ? '' : "<lastmod>{$lastmod}</lastmod>");
        }

        return '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $urls . '</urlset>';
    }

    private function planner(Response $response): ArticlePlanner
    {
        $guard = new UrlGuard([80, 443], Closure::fromCallable(static fn(string $host): array => $host === 'adferiad.org' ? ['93.184.216.34'] : []));
        $fetcher = new Fetcher(new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]), $guard);

        return new ArticlePlanner($fetcher, new SitemapReader(), $this->store);
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

    private function remember(string $slug, string $lastmod, string $status = 'imported', ?int $pageID = 10): void
    {
        $state = new ItemState($slug, "https://adferiad.org/cym/{$slug}/", $pageID, null, null, new DateTimeImmutable($lastmod), 'hash', $status);
        $this->store->save($state);
    }

    public function testEveryNewArticleIsPlannedNewestFirst(): void
    {
        $plan = $this->planner(new Response(200, [], self::sitemap([
            'old' => '2024-01-01T00:00:00+00:00',
            'newest' => '2025-04-01T00:00:00+00:00',
            'middle' => '2024-09-01T00:00:00+00:00',
        ])))->plan(self::config(), new DateTimeImmutable(self::NOW));

        self::assertSame(['newest', 'middle', 'old'], self::slugs($plan->entries));
        self::assertSame(0, $plan->upToDate);
        self::assertSame(1, $plan->notEligible, 'the news index page');
        self::assertSame(0, $plan->beyondLimit);
    }

    public function testArticlesAlreadyImportedFromTheListedVersionAreLeftOut(): void
    {
        $this->remember('done', '2025-03-01T00:00:00+00:00');
        $this->remember('changed', '2025-02-01T00:00:00+00:00');

        $plan = $this->planner(new Response(200, [], self::sitemap([
            'done' => '2025-03-01T00:00:00+00:00',
            'changed' => '2025-03-15T00:00:00+00:00',
            'fresh' => '2025-03-20T00:00:00+00:00',
        ])))->plan(self::config(), new DateTimeImmutable(self::NOW));

        self::assertSame(['fresh', 'changed'], self::slugs($plan->entries));
        self::assertSame(1, $plan->upToDate);
    }

    public function testAFailedArticleIsPlannedAgain(): void
    {
        $this->remember('broken', '2025-03-01T00:00:00+00:00', 'failed');

        $plan = $this->planner(new Response(200, [], self::sitemap(['broken' => '2025-03-01T00:00:00+00:00'])))->plan(self::config(), new DateTimeImmutable(self::NOW));

        self::assertSame(['broken'], self::slugs($plan->entries));
    }

    public function testAnArticleWithoutADateIsAlwaysPlannedAndComesLast(): void
    {
        $this->remember('undated', '2025-03-01T00:00:00+00:00');

        $plan = $this->planner(new Response(200, [], self::sitemap([
            'undated' => null,
            'dated' => '2025-03-01T00:00:00+00:00',
        ])))->plan(self::config(), new DateTimeImmutable(self::NOW));

        self::assertSame(['dated', 'undated'], self::slugs($plan->entries));
    }

    public function testSinceDaysLeavesOutOlderArticles(): void
    {
        $plan = $this->planner(new Response(200, [], self::sitemap([
            'recent' => '2025-04-05T00:00:00+00:00',
            'borderline' => '2025-04-03T12:00:00+00:00',
            'old' => '2025-01-01T00:00:00+00:00',
        ])))->plan(self::config(['sinceDays' => 7]), new DateTimeImmutable(self::NOW));

        self::assertSame(['recent'], self::slugs($plan->entries));
        self::assertSame(3, $plan->notEligible, 'index page, borderline (on the cutoff) and old');
    }

    public function testMaxPerRunKeepsTheNewestAndCountsTheRest(): void
    {
        $plan = $this->planner(new Response(200, [], self::sitemap([
            'a' => '2025-01-01T00:00:00+00:00',
            'b' => '2025-02-01T00:00:00+00:00',
            'c' => '2025-03-01T00:00:00+00:00',
            'd' => '2025-04-01T00:00:00+00:00',
        ])))->plan(self::config(['maxPerRun' => 2]), new DateTimeImmutable(self::NOW));

        self::assertSame(['d', 'c'], self::slugs($plan->entries));
        self::assertSame(2, $plan->beyondLimit);
    }

    public function testTheLimitOnlyCountsArticlesThatNeedWork(): void
    {
        $this->remember('d', '2025-04-01T00:00:00+00:00');

        $plan = $this->planner(new Response(200, [], self::sitemap([
            'a' => '2025-01-01T00:00:00+00:00',
            'b' => '2025-02-01T00:00:00+00:00',
            'd' => '2025-04-01T00:00:00+00:00',
        ])))->plan(self::config(['maxPerRun' => 1]), new DateTimeImmutable(self::NOW));

        self::assertSame(['b'], self::slugs($plan->entries));
        self::assertSame(1, $plan->upToDate);
        self::assertSame(1, $plan->beyondLimit);
    }

    public function testAnEmptySitemapPlansNothing(): void
    {
        $plan = $this->planner(new Response(200, [], self::sitemap([])))->plan(self::config(), new DateTimeImmutable(self::NOW));

        self::assertSame([], $plan->entries);
    }

    public function testASitemapThatCanNotBeFetchedIsAnError(): void
    {
        $this->expectException(FetchException::class);

        $this->planner(new Response(500))->plan(self::config(), new DateTimeImmutable(self::NOW));
    }

    public function testASitemapThatIsNotASitemapIsAnError(): void
    {
        $this->expectException(ScrapeException::class);

        $this->planner(new Response(200, [], '<html><body>Not a sitemap</body></html>'))->plan(self::config(), new DateTimeImmutable(self::NOW));
    }

    protected function setUp(): void
    {
        $this->store = new FakeStore();
    }
}
