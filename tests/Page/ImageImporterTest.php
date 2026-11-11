<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Page;

use Closure;
use RuntimeException;
use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use Psr\Log\AbstractLogger;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Handler\MockHandler;
use ContentImporterKit\Page\ImageSink;
use ContentImporterKit\Scrape\Fetcher;
use ContentImporterKit\Security\UrlGuard;
use ContentImporterKit\Page\ImageImporter;

final class ImageImporterTest extends TestCase
{
    /** A valid 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var list<array{request: Request, options: array<string, mixed>}> */
    private array $requests = [];

    private RecordingSink $sink;

    private ImageLogger $logger;

    private string $temporary;

    private static function png(): string
    {
        return (string) base64_decode(self::PNG, true);
    }

    /**
     * @param list<Response|\Throwable> $responses
     */
    private function importer(array $responses): ImageImporter
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->requests));
        $guard = new UrlGuard([80, 443], Closure::fromCallable(static fn(string $host): array => $host === 'cdn.example.org' ? ['93.184.216.34'] : []));

        return new ImageImporter(new Fetcher(new Client(['handler' => $stack]), $guard), $this->sink, $this->logger, $this->temporary);
    }

    private function requestCount(): int
    {
        return count($this->requests);
    }

    public function testAValidImageIsStoredAndItsFileIdReturned(): void
    {
        $id = $this->importer([new Response(200, [], self::png())])->import('https://cdn.example.org/uploads/logo.png');

        self::assertSame(501, $id);
        self::assertSame(['logo.png'], $this->sink->names);
        self::assertSame(self::png(), $this->sink->contents[0]);
    }

    public function testTheSameAddressIsDownloadedOnce(): void
    {
        $importer = $this->importer([new Response(200, [], self::png())]);

        $first = $importer->import('https://cdn.example.org/a.png');
        $second = $importer->import('https://cdn.example.org/a.png');

        self::assertSame(501, $first);
        self::assertSame($first, $second);
        self::assertSame(1, $this->requestCount());
        self::assertCount(1, $this->sink->names);
    }

    public function testAFailureIsRememberedToo(): void
    {
        $importer = $this->importer([new Response(404)]);

        self::assertNull($importer->import('https://cdn.example.org/missing.png'));
        self::assertNull($importer->import('https://cdn.example.org/missing.png'));

        self::assertSame(1, $this->requestCount());
    }

    public function testAFileThatIsNotAnImageIsRefusedWhateverItIsCalled(): void
    {
        $importer = $this->importer([new Response(200, [], '<?php echo "not an image";')]);

        self::assertNull($importer->import('https://cdn.example.org/shell.png'));
        self::assertSame([], $this->sink->names);
        self::assertStringContainsString('not a JPEG or PNG', $this->logger->lines[0]);
    }

    public function testAnHtmlErrorPageServedAsAnImageIsRefused(): void
    {
        $importer = $this->importer([new Response(200, ['Content-Type' => 'image/png'], '<html><body>Blocked</body></html>')]);

        self::assertNull($importer->import('https://cdn.example.org/a.png'));
        self::assertSame([], $this->sink->names);
    }

    public function testAGifIsRefusedBecauseOnlyJpegAndPngAreImported(): void
    {
        $gif = (string) base64_decode('R0lGODlhAQABAAAAACw=', true);

        self::assertNull($this->importer([new Response(200, [], $gif)])->import('https://cdn.example.org/a.png'));
        self::assertSame([], $this->sink->names);
    }

    public function testTheFileGetsTheExtensionOfWhatItReallyIs(): void
    {
        $this->importer([new Response(200, [], self::png())])->import('https://cdn.example.org/photo.jpg');

        self::assertSame(['photo.png'], $this->sink->names);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function awkwardAddresses(): iterable
    {
        yield 'query string' => ['https://cdn.example.org/a/b.png?w=100&h=200', 'b.png'];
        yield 'url encoded characters' => ['https://cdn.example.org/a%20b%2Fc.png', 'a-20b-2Fc.png'];
        yield 'path traversal' => ['https://cdn.example.org/..%2F..%2Fetc%2Fpasswd.png', '2F.-2Fetc-2Fpasswd.png'];
        yield 'leading dots' => ['https://cdn.example.org/...hidden.png', 'hidden.png'];
        yield 'welsh characters' => ['https://cdn.example.org/%C5%B5yl.png', 'C5-B5yl.png'];
        yield 'no file name' => ['https://cdn.example.org/', 'image-%s.png'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('awkwardAddresses')]
    public function testTheFileNameIsAlwaysSafe(string $url, string $expected): void
    {
        $this->importer([new Response(200, [], self::png())])->import($url);

        $expected = str_contains($expected, '%s') ? sprintf($expected, substr(sha1($url), 0, 12)) : $expected;
        self::assertSame([$expected], $this->sink->names);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(png|jpg)$/', $this->sink->names[0]);
    }

    public function testAnInternalAddressIsRefusedWithoutAnyRequest(): void
    {
        $importer = $this->importer([new Response(200, [], self::png())]);

        self::assertNull($importer->import('http://127.0.0.1/a.png'));
        self::assertNull($importer->import('http://169.254.169.254/latest/meta-data/x.png'));
        self::assertSame(0, $this->requestCount());
        self::assertCount(2, $this->logger->lines);
    }

    public function testARedirectToAnInternalAddressIsRefused(): void
    {
        $importer = $this->importer([new Response(302, ['Location' => 'http://10.0.0.5/a.png']), new Response(200, [], self::png())]);

        self::assertNull($importer->import('https://cdn.example.org/a.png'));
        self::assertSame(1, $this->requestCount());
        self::assertSame([], $this->sink->names);
    }

    public function testTheFileManagerRefusingTheFileIsAFailureNotACrash(): void
    {
        $this->sink->refuse = true;

        self::assertNull($this->importer([new Response(200, [], self::png())])->import('https://cdn.example.org/a.png'));
        self::assertStringContainsString('file manager said no', $this->logger->lines[0]);
    }

    public function testTheTemporaryFileAndFolderAreRemovedAfterwards(): void
    {
        $this->importer([new Response(200, [], self::png())])->import('https://cdn.example.org/a.png');

        self::assertSame([], glob($this->temporary . '/*'));
    }

    public function testTheTemporaryFileIsRemovedEvenWhenStoringFails(): void
    {
        $this->sink->refuse = true;

        $this->importer([new Response(200, [], self::png())])->import('https://cdn.example.org/a.png');

        self::assertSame([], glob($this->temporary . '/*'));
    }

    public function testLogLinesCannotBeForgedThroughTheAddress(): void
    {
        $this->importer([])->import("http://127.0.0.1/a.png\n[2025-01-01] CRITICAL fake entry");

        self::assertStringNotContainsString("\n", $this->logger->lines[0]);
    }

    protected function setUp(): void
    {
        $this->requests = [];
        $this->sink = new RecordingSink();
        $this->logger = new ImageLogger();
        $this->temporary = sys_get_temp_dir() . '/cik-test-' . bin2hex(random_bytes(4));
        mkdir($this->temporary);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporary . '/*') ?: [] as $leftover) {
            is_dir($leftover) ? @rmdir($leftover) : @unlink($leftover);
        }
        @rmdir($this->temporary);
    }
}

/**
 * @internal
 */
final class RecordingSink implements ImageSink
{
    /** @var list<string> */
    public array $names = [];

    /** @var list<string> */
    public array $contents = [];

    public bool $refuse = false;

    public int $nextId = 501;

    public function store(string $localPath): int
    {
        if ($this->refuse) {
            throw new RuntimeException('The file manager said no.');
        }
        $this->names[] = basename($localPath);
        $this->contents[] = (string) file_get_contents($localPath);

        return $this->nextId++;
    }
}

/**
 * @internal
 */
final class ImageLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, $message, array $context = [])
    {
        $this->lines[] = (string) $message;
    }
}
