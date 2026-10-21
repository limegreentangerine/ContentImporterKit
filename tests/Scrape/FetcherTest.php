<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Scrape;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Handler\MockHandler;
use ContentImporterKit\Scrape\Fetcher;
use ContentImporterKit\Security\UrlGuard;
use GuzzleHttp\Exception\ConnectException;
use ContentImporterKit\Scrape\FetchException;
use PHPUnit\Framework\Attributes\DataProvider;

final class FetcherTest extends TestCase
{
    /** @var list<array{request: Request, options: array<string, mixed>}> */
    private array $history = [];

    /**
     * @param list<Response|\Throwable> $queue
     */
    private function fetcher(array $queue, int $maxBytes = 5_000_000, int $maxRedirects = 3): Fetcher
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $dns = [
            'adferiad.org' => ['93.184.216.34'],
            'www.adferiad.org' => ['93.184.216.35'],
            'cdn.example.org' => ['2606:4700::1111'],
            'internal.example.org' => ['10.0.0.5'],
        ];
        $guard = new UrlGuard([80, 443], Closure::fromCallable(static fn(string $host): array => $dns[$host] ?? []));

        return new Fetcher(new Client(['handler' => $stack]), $guard, maxBytes: $maxBytes, maxRedirects: $maxRedirects);
    }

    public function testASuccessfulFetchReturnsTheBodyAndValidators(): void
    {
        $fetcher = $this->fetcher([new Response(200, ['ETag' => '"abc"', 'Last-Modified' => 'Tue, 14 Mar 2025 16:11:16 GMT'], '<html>hello</html>')]);

        $result = $fetcher->get('https://adferiad.org/cym/post/');

        self::assertSame('<html>hello</html>', $result->body);
        self::assertSame(200, $result->status);
        self::assertSame('https://adferiad.org/cym/post/', $result->url);
        self::assertSame('"abc"', $result->etag);
        self::assertSame('Tue, 14 Mar 2025 16:11:16 GMT', $result->lastModified);
        self::assertFalse($result->notModified);
    }

    public function testTheRequestIsPinnedToTheValidatedAddressWithoutFollowingRedirects(): void
    {
        $this->fetcher([new Response(200, [], 'ok')])->get('https://adferiad.org/cym/post/');

        self::assertCount(1, $this->history);
        $options = $this->history[0]['options'];
        self::assertSame(['adferiad.org:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
        self::assertFalse($options['allow_redirects']);
        self::assertSame(5, $options['connect_timeout']);
        self::assertSame(20, $options['timeout']);
        self::assertStringContainsString('ContentImporterKit', $this->history[0]['request']->getHeaderLine('User-Agent'));
    }

    public function testRequestsAreNeverStreamedBecauseThatBypassesCurlAndItsAddressPin(): void
    {
        $this->fetcher([new Response(200, [], 'ok')])->get('https://adferiad.org/cym/post/');

        $options = $this->history[0]['options'];

        // 'stream' => true switches Guzzle to PHP's stream wrapper: CURLOPT_RESOLVE is ignored (the pinned address
        // would not be used) and chunked responses are slow. A mock handler can't show that, so it is checked here.
        self::assertArrayNotHasKey('stream', $options);
        self::assertArrayHasKey(CURLOPT_RESOLVE, $options['curl']);
    }

    public function testTheDownloadIsStoppedAsSoonAsItPassesTheLimit(): void
    {
        $this->fetcher([new Response(200, [], 'ok')], maxBytes: 1000)->get('https://adferiad.org/cym/post/');

        $progress = $this->history[0]['options']['progress'];

        // Within the limit, including exactly at it: no exception.
        $progress(0, 0);
        $progress(900, 500);
        $progress(1000, 1000);
        $this->expectException(FetchException::class);
        $this->expectExceptionMessageMatches('/too large/');

        // A declared size above the limit stops the transfer before any of the body arrives.
        $progress(1001, 10);
    }

    public function testTheDownloadIsStoppedWhenWhatHasArrivedPassesTheLimit(): void
    {
        $this->fetcher([new Response(200, [], 'ok')], maxBytes: 1000)->get('https://adferiad.org/cym/post/');

        $this->expectException(FetchException::class);

        $this->history[0]['options']['progress'](0, 1001);
    }

    public function testConditionalHeadersAreSentAndANotModifiedAnswerIsReported(): void
    {
        $fetcher = $this->fetcher([new Response(304, ['ETag' => '"abc"'])]);

        $result = $fetcher->get('https://adferiad.org/cym/post/', ['If-None-Match' => '"abc"']);

        self::assertTrue($result->notModified);
        self::assertSame('', $result->body);
        self::assertSame('"abc"', $this->history[0]['request']->getHeaderLine('If-None-Match'));
    }

    public function testRedirectsAreFollowedAndEveryHopIsPinned(): void
    {
        $fetcher = $this->fetcher([
            new Response(301, ['Location' => 'https://www.adferiad.org/cym/post/']),
            new Response(302, ['Location' => '/cym/final/']),
            new Response(200, [], 'final body'),
        ]);

        $result = $fetcher->get('https://adferiad.org/cym/post/');

        self::assertSame('final body', $result->body);
        self::assertSame('https://www.adferiad.org/cym/final/', $result->url);
        self::assertCount(3, $this->history);
        self::assertSame(['www.adferiad.org:443:93.184.216.35'], $this->history[1]['options']['curl'][CURLOPT_RESOLVE]);
        self::assertSame(['www.adferiad.org:443:93.184.216.35'], $this->history[2]['options']['curl'][CURLOPT_RESOLVE]);
    }

    public function testAnIpv6OnlyHostIsPinnedWithBrackets(): void
    {
        $this->fetcher([new Response(200, [], 'ok')])->get('https://cdn.example.org/a.png');

        self::assertSame(['cdn.example.org:443:[2606:4700::1111]'], $this->history[0]['options']['curl'][CURLOPT_RESOLVE]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeRedirectTargets(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/admin'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'host that resolves privately' => ['https://internal.example.org/secret'];
        yield 'localhost' => ['http://localhost/'];
        yield 'unresolvable host' => ['https://nowhere.example.org/'];
    }

    #[DataProvider('unsafeRedirectTargets')]
    public function testARedirectToAnInternalAddressIsRefusedBeforeAnyRequestIsSent(string $target): void
    {
        $fetcher = $this->fetcher([
            new Response(302, ['Location' => $target]),
            new Response(200, [], 'must never be requested'),
        ]);

        try {
            $fetcher->get('https://adferiad.org/cym/post/');
            self::fail('Expected a FetchException.');
        } catch (FetchException) {
            self::assertCount(1, $this->history, 'only the first request may be sent');
        }
    }

    public function testAnUnsafeStartingUrlSendsNothing(): void
    {
        $fetcher = $this->fetcher([new Response(200, [], 'ok')]);

        try {
            $fetcher->get('http://10.0.0.1/');
            self::fail('Expected a FetchException.');
        } catch (FetchException) {
            self::assertCount(0, $this->history);
        }
    }

    public function testTooManyRedirectsAreRefused(): void
    {
        $queue = array_fill(0, 5, new Response(302, ['Location' => 'https://adferiad.org/cym/loop/']));

        $this->expectException(FetchException::class);
        $this->expectExceptionMessageMatches('/too many/');

        $this->fetcher($queue, maxRedirects: 3)->get('https://adferiad.org/cym/loop/');
    }

    public function testARedirectWithoutAUsableLocationIsRefused(): void
    {
        $this->expectException(FetchException::class);

        $this->fetcher([new Response(302), new Response(200, [], 'x')])->get('https://adferiad.org/cym/post/');
    }

    public function testARedirectToAJavascriptUrlIsRefused(): void
    {
        $this->expectException(FetchException::class);

        $this->fetcher([new Response(302, ['Location' => 'javascript:alert(1)'])])->get('https://adferiad.org/cym/post/');
    }

    public function testErrorStatusesCarryTheirCode(): void
    {
        foreach ([404, 410, 500, 503] as $status) {
            try {
                $this->fetcher([new Response($status, [], 'error page')])->get('https://adferiad.org/cym/post/');
                self::fail('Expected a FetchException.');
            } catch (FetchException $e) {
                self::assertSame($status, $e->httpStatus());
                self::assertStringNotContainsString('error page', $e->getMessage());
            }
        }
    }

    public function testAResponseThatIsTooLargeIsRefusedWhileReading(): void
    {
        $this->expectException(FetchException::class);
        $this->expectExceptionMessageMatches('/too large/');

        $this->fetcher([new Response(200, [], str_repeat('a', 5000))], maxBytes: 1000)->get('https://adferiad.org/cym/post/');
    }

    public function testADeclaredLengthThatIsTooLargeIsRefusedUpFront(): void
    {
        $this->expectException(FetchException::class);
        $this->expectExceptionMessageMatches('/too large/');

        $this->fetcher([new Response(200, ['Content-Length' => '999999999'], 'tiny')], maxBytes: 1000)->get('https://adferiad.org/cym/post/');
    }

    public function testABodyExactlyAtTheLimitIsAccepted(): void
    {
        $result = $this->fetcher([new Response(200, [], str_repeat('a', 1000))], maxBytes: 1000)->get('https://adferiad.org/cym/post/');

        self::assertSame(1000, strlen($result->body));
    }

    public function testConnectionFailuresAreReportedWithoutLeakingDetails(): void
    {
        $fetcher = $this->fetcher([new ConnectException('cURL error 6: Could not resolve host: secret.internal', new Request('GET', 'https://adferiad.org/'))]);

        try {
            $fetcher->get('https://adferiad.org/cym/post/');
            self::fail('Expected a FetchException.');
        } catch (FetchException $e) {
            self::assertSame('The request failed.', $e->getMessage());
            self::assertInstanceOf(ConnectException::class, $e->getPrevious());
        }
    }
}
