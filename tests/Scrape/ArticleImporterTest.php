<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Scrape;

use Closure;
use RuntimeException;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Handler\MockHandler;
use ContentImporterKit\Scrape\Fetcher;
use ContentImporterKit\Import\Sanitiser;
use ContentImporterKit\Scrape\ItemState;
use ContentImporterKit\Security\UrlGuard;
use ContentImporterKit\Scrape\SitemapEntry;
use ContentImporterKit\Scrape\SourceConfig;
use ContentImporterKit\Import\PublishTarget;
use ContentImporterKit\Scrape\ArticleParser;
use ContentImporterKit\Scrape\ImportOutcome;
use ContentImporterKit\Scrape\ArticleImporter;
use ContentImporterKit\Tests\Scrape\Support\FakeStore;
use ContentImporterKit\Tests\Scrape\Support\FakeLinker;
use ContentImporterKit\Tests\Scrape\Support\FakeLogger;
use ContentImporterKit\Tests\Scrape\Support\FakePublisher;

final class ArticleImporterTest extends TestCase
{
    private const URL = 'https://adferiad.org/cym/adferiad-yn-cyhoeddi-ymgyrch/';

    private const ENGLISH_URL = 'https://adferiad.org/latest/campaign/';

    private const LANGUAGES = ['locales' => 'en-gb | en_GB | 9 | https://adferiad.org/latest/'];

    /** @var list<array{request: Request, options: array<string, mixed>}> */
    private array $requests = [];

    private FakeStore $store;

    private FakePublisher $publisher;

    private FakeLogger $logger;

    /**
     * The history middleware fills $this->requests by reference, which static analysis can not follow.
     */
    private function sentRequest(int $index): Request
    {
        return $this->requests[$index]['request'];
    }

    private static function html(): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/article.html');
    }

    private static function entry(?string $lastmod = '2025-03-14T16:11:16+00:00'): SitemapEntry
    {
        return new SitemapEntry(self::URL, 'adferiad-yn-cyhoeddi-ymgyrch', $lastmod === null ? null : new DateTimeImmutable($lastmod));
    }

    /**
     * @param list<Response|\Throwable> $responses
     * @param array<string, mixed>      $config    settings that replace those of the source
     */
    private function importer(array $responses, array $config = [], ?FakeLinker $linker = null): ArticleImporter
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->requests));
        $guard = new UrlGuard([80, 443], Closure::fromCallable(static fn(string $host): array => $host === 'adferiad.org' ? ['93.184.216.34'] : []));

        return new ArticleImporter(
            new Fetcher(new Client(['handler' => $stack]), $guard),
            new ArticleParser(),
            new Sanitiser(),
            $this->publisher,
            $this->store,
            SourceConfig::fromArray($config + [
                'newsUrl' => 'https://adferiad.org/cym/',
                'sitemapUrl' => 'https://adferiad.org/cym/post-sitemap.xml',
                'titleSelector' => 'h1',
                'articleSelector' => '.full_width.single_post .two_thirds',
                'bannerSelector' => '.page_banner_wrap .image_container .image_wrap',
            ]),
            new PublishTarget(1, 'page', 'full', 'Main', ['banner' => 'thumbnail']),
            $this->logger,
            $linker,
        );
    }

    private static function english(): SitemapEntry
    {
        return new SitemapEntry(self::ENGLISH_URL, 'campaign', new DateTimeImmutable('2025-03-14T16:11:16+00:00'), 'en-gb', [self::URL]);
    }

    public function testANewArticleIsImportedAndRemembered(): void
    {
        $outcome = $this->importer([new Response(200, ['ETag' => '"v1"', 'Last-Modified' => 'Fri, 14 Mar 2025 16:11:16 GMT'], self::html())])->import(self::entry());

        self::assertSame(ImportOutcome::Imported, $outcome);

        $state = $this->store->items['adferiad-yn-cyhoeddi-ymgyrch'];
        self::assertSame('imported', $state->status);
        self::assertSame(101, $state->pageID);
        self::assertSame('"v1"', $state->etag);
        self::assertSame('Fri, 14 Mar 2025 16:11:16 GMT', $state->lastModified);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $state->contentHash);
        self::assertNull($state->error);
        self::assertSame(self::URL, $state->url);
        self::assertSame('2025-03-14', $state->remoteLastmod?->format('Y-m-d'));
    }

    public function testEachStepOfAnImportIsTimed(): void
    {
        $importer = $this->importer([new Response(200, [], self::html())]);

        $importer->import(self::entry());

        $timings = $importer->lastTimings();
        self::assertSame(['fetch', 'parse', 'publish'], array_keys($timings));
        foreach ($timings as $seconds) {
            self::assertGreaterThanOrEqual(0.0, $seconds);
        }
    }

    public function testAStepThatDidNotHappenIsZero(): void
    {
        $this->importer([new Response(200, [], self::html())])->import(self::entry());

        // The article is current, so nothing is fetched, parsed or published.
        $importer = $this->importer([]);
        $importer->import(self::entry());

        self::assertSame(['fetch' => 0.0, 'parse' => 0.0, 'publish' => 0.0], $importer->lastTimings());
    }

    public function testANotModifiedAnswerIsFetchedButNeitherParsedNorPublished(): void
    {
        $this->importer([new Response(200, ['ETag' => '"v1"'], self::html())])->import(self::entry());

        $importer = $this->importer([new Response(304)]);
        $importer->import(self::entry('2025-04-01T00:00:00+00:00'));

        $timings = $importer->lastTimings();
        self::assertGreaterThan(0.0, $timings['fetch']);
        self::assertSame(0.0, $timings['parse']);
        self::assertSame(0.0, $timings['publish']);
    }

    public function testTheTimingsStartAgainForEveryArticle(): void
    {
        $importer = $this->importer([new Response(200, [], self::html()), new Response(404)]);
        $importer->import(self::entry());
        $first = $importer->lastTimings();

        $importer->import(new SitemapEntry('https://adferiad.org/cym/other/', 'other', new DateTimeImmutable('2025-01-01')));

        self::assertGreaterThan(0.0, $first['publish']);
        self::assertSame(0.0, $importer->lastTimings()['publish'], 'the second article failed before publishing');
    }

    public function testThePublisherOnlyEverSeesSanitisedHtml(): void
    {
        $this->importer([new Response(200, [], self::html())])->import(self::entry());

        self::assertCount(1, $this->publisher->calls);
        $body = $this->publisher->calls[0]['article']->record->bodyBlocks[0]['data'];
        self::assertStringContainsString('Ŵyl y Cyfeillgarwch', $body);
        self::assertStringNotContainsString('<script', $body);
        self::assertStringNotContainsString('javascript:', $body);
        self::assertNull($this->publisher->calls[0]['existingPageID']);
        self::assertSame('Main', $this->publisher->calls[0]['target']->areaName);
    }

    public function testAnUnchangedSitemapDateMeansNoRequestAtAll(): void
    {
        $this->importer([new Response(200, [], self::html())])->import(self::entry());
        $this->requests = [];

        $outcome = $this->importer([])->import(self::entry());

        self::assertSame(ImportOutcome::Unchanged, $outcome);
        self::assertCount(0, $this->requests);
        self::assertCount(1, $this->publisher->calls, 'not published again');
    }

    public function testAChangedSitemapDateWithA304KeepsThePageAndSendsConditionalHeaders(): void
    {
        $this->importer([new Response(200, ['ETag' => '"v1"', 'Last-Modified' => 'Fri, 14 Mar 2025 16:11:16 GMT'], self::html())])->import(self::entry());
        $this->requests = [];

        $outcome = $this->importer([new Response(304, ['ETag' => '"v1"'])])->import(self::entry('2025-04-01T00:00:00+00:00'));

        self::assertSame(ImportOutcome::Unchanged, $outcome);
        self::assertSame('"v1"', $this->sentRequest(0)->getHeaderLine('If-None-Match'));
        self::assertSame('Fri, 14 Mar 2025 16:11:16 GMT', $this->sentRequest(0)->getHeaderLine('If-Modified-Since'));
        self::assertCount(1, $this->publisher->calls);
        self::assertSame('2025-04-01', $this->store->items['adferiad-yn-cyhoeddi-ymgyrch']->remoteLastmod?->format('Y-m-d'));
        self::assertSame('unchanged', $this->store->items['adferiad-yn-cyhoeddi-ymgyrch']->status);
    }

    public function testANewArticleSendsNoConditionalHeaders(): void
    {
        $this->importer([new Response(200, [], self::html())])->import(self::entry());

        self::assertSame('', $this->sentRequest(0)->getHeaderLine('If-None-Match'));
        self::assertSame('', $this->sentRequest(0)->getHeaderLine('If-Modified-Since'));
    }

    public function testAChangedDateWithIdenticalContentDoesNotTouchThePage(): void
    {
        $this->importer([new Response(200, [], self::html())])->import(self::entry());

        $outcome = $this->importer([new Response(200, [], self::html())])->import(self::entry('2025-04-01T00:00:00+00:00'));

        self::assertSame(ImportOutcome::Unchanged, $outcome);
        self::assertCount(1, $this->publisher->calls);
    }

    public function testChangedContentUpdatesTheExistingPageInPlace(): void
    {
        $this->importer([new Response(200, [], self::html())])->import(self::entry());
        $changed = str_replace('yn dechrau heddiw', 'wedi dechrau ddoe', self::html());

        $outcome = $this->importer([new Response(200, [], $changed)])->import(self::entry('2025-04-01T00:00:00+00:00'));

        self::assertSame(ImportOutcome::Updated, $outcome);
        self::assertCount(2, $this->publisher->calls);
        self::assertSame(101, $this->publisher->calls[1]['existingPageID']);
        self::assertSame('updated', $this->store->items['adferiad-yn-cyhoeddi-ymgyrch']->status);
    }

    public function testAnArticleWithoutASitemapDateIsRefetchedEveryTime(): void
    {
        $this->importer([new Response(200, [], self::html())])->import(self::entry(null));
        $this->requests = [];

        $this->importer([new Response(304)])->import(self::entry(null));

        self::assertCount(1, $this->requests);
    }

    public function testADeletedPageIsRecreated(): void
    {
        $this->importer([new Response(200, [], self::html())])->import(self::entry());
        $this->publisher->missing = true;
        $changed = str_replace('yn dechrau heddiw', 'wedi dechrau ddoe', self::html());

        $outcome = $this->importer([new Response(200, [], $changed)])->import(self::entry('2025-04-01T00:00:00+00:00'));

        self::assertSame(ImportOutcome::Imported, $outcome);
        self::assertSame(101, $this->publisher->calls[1]['existingPageID'], 'first tried to update');
        self::assertNull($this->publisher->calls[2]['existingPageID'], 'then created a new page');
        self::assertSame(102, $this->store->items['adferiad-yn-cyhoeddi-ymgyrch']->pageID);
    }

    public function testAPageThatIsNotOurOwnAtTheSameAddressIsAFailure(): void
    {
        $this->publisher->exists = true;

        $outcome = $this->importer([new Response(200, [], self::html())])->import(self::entry());

        self::assertSame(ImportOutcome::Failed, $outcome);
        $state = $this->store->items['adferiad-yn-cyhoeddi-ymgyrch'];
        self::assertSame('failed', $state->status);
        self::assertStringContainsString('already exists', (string) $state->error);
        self::assertNull($state->pageID);
    }

    public function testAnHttpErrorIsAFailureWithItsStatus(): void
    {
        $outcome = $this->importer([new Response(404, [], 'not found')])->import(self::entry());

        self::assertSame(ImportOutcome::Failed, $outcome);
        self::assertStringContainsString('HTTP 404', (string) $this->store->items['adferiad-yn-cyhoeddi-ymgyrch']->error);
        self::assertCount(0, $this->publisher->calls);
    }

    public function testAPageWithoutTheArticleIsAFailureNotACrash(): void
    {
        $outcome = $this->importer([new Response(200, [], '<html><body><h1>Title</h1><p>No article here</p></body></html>')])->import(self::entry());

        self::assertSame(ImportOutcome::Failed, $outcome);
        self::assertStringContainsString('content', (string) $this->store->items['adferiad-yn-cyhoeddi-ymgyrch']->error);
    }

    public function testAnInvalidRecordIsAFailureWithTheFieldNamed(): void
    {
        $html = (string) preg_replace('/<meta property="article:published_time"[^>]*>/', '', self::html());

        $outcome = $this->importer([new Response(200, [], $html)])->import(self::entry(null));

        self::assertSame(ImportOutcome::Failed, $outcome);
        self::assertStringContainsString('published_date', (string) $this->store->items['adferiad-yn-cyhoeddi-ymgyrch']->error);
    }

    public function testUnexpectedErrorsAreLoggedInFullButOnlyTheClassIsStored(): void
    {
        $this->publisher->throw = new RuntimeException('SQLSTATE[HY000] password=hunter2 at /var/www/secret/path.php');

        $outcome = $this->importer([new Response(200, [], self::html())])->import(self::entry());

        self::assertSame(ImportOutcome::Failed, $outcome);
        $error = (string) $this->store->items['adferiad-yn-cyhoeddi-ymgyrch']->error;
        self::assertStringContainsString('RuntimeException', $error);
        self::assertStringNotContainsString('hunter2', $error);
        self::assertStringNotContainsString('/var/www', $error);
        self::assertStringContainsString('hunter2', $this->logger->lines[0], 'the full detail is in the log');
    }

    public function testAFailedArticleIsRetriedNextTimeEvenWithTheSameSitemapDate(): void
    {
        $this->importer([new Response(500)])->import(self::entry());
        $this->requests = [];

        $outcome = $this->importer([new Response(200, [], self::html())])->import(self::entry());

        self::assertSame(ImportOutcome::Imported, $outcome);
        self::assertCount(1, $this->requests);
        self::assertNull($this->store->items['adferiad-yn-cyhoeddi-ymgyrch']->error);
    }

    public function testAFailureAfterASuccessKeepsTheKnownPage(): void
    {
        $this->importer([new Response(200, [], self::html())])->import(self::entry());

        $this->importer([new Response(503)])->import(self::entry('2025-04-01T00:00:00+00:00'));

        $state = $this->store->items['adferiad-yn-cyhoeddi-ymgyrch'];
        self::assertSame('failed', $state->status);
        self::assertSame(101, $state->pageID);
    }

    public function testFailuresAreLogged(): void
    {
        $this->importer([new Response(404)])->import(self::entry());

        self::assertCount(1, $this->logger->lines);
        self::assertStringContainsString('adferiad-yn-cyhoeddi-ymgyrch', $this->logger->lines[0]);
    }

    public function testAnUnsafeUrlIsRefusedBeforeAnyRequest(): void
    {
        $entry = new SitemapEntry('http://169.254.169.254/latest/', 'metadata', new DateTimeImmutable('2025-01-01'));

        $outcome = $this->importer([new Response(200, [], self::html())])->import($entry);

        self::assertSame(ImportOutcome::Failed, $outcome);
        self::assertCount(0, $this->requests);
    }

    public function testAnArticleInAnotherLanguageGoesUnderThatLanguagesParent(): void
    {
        $outcome = $this->importer([new Response(200, [], self::html())], self::LANGUAGES)->import(self::english());

        self::assertSame(ImportOutcome::Imported, $outcome);
        self::assertSame(9, $this->publisher->calls[0]['target']->parentPageID);
        self::assertSame('Main', $this->publisher->calls[0]['target']->areaName, 'the rest of the target is the source\'s');

        $state = $this->store->find('campaign', 'en-gb');
        self::assertSame('en-gb', $state?->hreflang);
        self::assertNull($this->store->find('campaign'), 'it is not remembered as the source\'s own language');
    }

    public function testTheOwnLanguageKeepsTheSourcesParent(): void
    {
        $this->importer([new Response(200, [], self::html())], self::LANGUAGES)->import(self::entry());

        self::assertSame(1, $this->publisher->calls[0]['target']->parentPageID);
    }

    public function testALanguageTheSourceDoesNotHaveFailsOnlyThatArticle(): void
    {
        $entry = new SitemapEntry(self::ENGLISH_URL, 'campaign', null, 'fr');

        $outcome = $this->importer([new Response(200, [], self::html())], self::LANGUAGES)->import($entry);

        self::assertSame(ImportOutcome::Failed, $outcome);
        self::assertSame([], $this->publisher->calls);
        self::assertSame('The source has no language "fr".', $this->store->find('campaign', 'fr')?->error);
    }

    public function testAPageIsLinkedToTranslationsThatAreAlreadyImported(): void
    {
        $this->store->save(new ItemState('adferiad-yn-cyhoeddi-ymgyrch', self::URL, 55));
        $linker = new FakeLinker();

        $this->importer([new Response(200, [], self::html())], self::LANGUAGES, $linker)->import(self::english());

        self::assertSame([['pageID' => 101, 'translations' => [55]]], $linker->calls);
    }

    public function testATranslationThatIsNotImportedYetIsNotWaitedFor(): void
    {
        $linker = new FakeLinker();

        $outcome = $this->importer([new Response(200, [], self::html())], self::LANGUAGES, $linker)->import(self::english());

        self::assertSame(ImportOutcome::Imported, $outcome);
        self::assertSame([], $linker->calls);
    }

    public function testATranslationWithoutAPageYetIsNotLinked(): void
    {
        $this->store->save(new ItemState('adferiad-yn-cyhoeddi-ymgyrch', self::URL, null, status: 'failed'));
        $linker = new FakeLinker();

        $this->importer([new Response(200, [], self::html())], self::LANGUAGES, $linker)->import(self::english());

        self::assertSame([], $linker->calls);
    }

    public function testALinkThatFailsDoesNotFailTheArticle(): void
    {
        $this->store->save(new ItemState('adferiad-yn-cyhoeddi-ymgyrch', self::URL, 55));
        $linker = new FakeLinker();
        $linker->fail = true;

        $outcome = $this->importer([new Response(200, [], self::html())], self::LANGUAGES, $linker)->import(self::english());

        self::assertSame(ImportOutcome::Imported, $outcome);
        self::assertSame('imported', $this->store->find('campaign', 'en-gb')?->status);
        self::assertStringContainsString('not linked', $this->logger->lines[0]);
    }

    public function testNothingIsLinkedWithoutAlternates(): void
    {
        $linker = new FakeLinker();

        $this->importer([new Response(200, [], self::html())], [], $linker)->import(self::entry());

        self::assertSame([], $linker->calls);
    }

    protected function setUp(): void
    {
        $this->requests = [];
        $this->store = new FakeStore();
        $this->publisher = new FakePublisher();
        $this->logger = new FakeLogger();
    }
}
