<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Log;

use FilesystemIterator;
use ClassKit\Log\Logger;
use RecursiveIteratorIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use ContentImporterKit\Log\ImporterLogger;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Everything this package logs goes through ImporterLogger. These tests fail if code is added that logs some other
 * way, so entries never end up in a channel nobody is filtering on.
 */
final class LoggingConventionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * Ways of logging that bypass the package logger.
     */
    private const FORBIDDEN = [
        'Concrete\'s Log facade' => '/\bLog::|^use\s+Log\s*;/m',
        'error_log()' => '/\berror_log\s*\(/',
        'creating a logger directly' => '/\bLoggerFactory\b|->createLogger\s*\(|new\s+\\\\?Monolog\\\\Logger\b/',
        'another package logger' => '/\bClassKit\\\\Log\\\\Logger\b(?!;)/',
    ];

    /**
     * @return list<string>
     */
    private static function sourceFiles(): array
    {
        $files = [self::ROOT . '/controller.php'];

        foreach (['controllers', 'elements', 'single_pages', 'src'] as $directory) {
            $path = self::ROOT . '/' . $directory;
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function files(): iterable
    {
        foreach (self::sourceFiles() as $file) {
            yield substr((string) realpath($file), strlen((string) realpath(self::ROOT)) + 1) => [$file];
        }
    }

    #[DataProvider('files')]
    public function testFileLogsOnlyThroughThePackageLogger(string $file): void
    {
        // The logger class itself is the one place that is allowed to talk to ClassKit's logger.
        if (str_ends_with($file, 'src/Log/ImporterLogger.php')) {
            $this->addToAssertionCount(1);

            return;
        }

        $code = (string) file_get_contents($file);
        foreach (self::FORBIDDEN as $what => $pattern) {
            self::assertSame(0, preg_match($pattern, $code), sprintf('%s uses %s. Log through ImporterLogger instead.', basename($file), $what));
        }
    }

    public function testTheLoggerExtendsClassKitsLogger(): void
    {
        self::assertSame(Logger::class, get_parent_class(ImporterLogger::class));
    }

    public function testTheLoggerHasItsOwnChannel(): void
    {
        self::assertSame('content_importer', ImporterLogger::CHANNEL);
    }

    public function testTheCheckWouldCatchOtherWaysOfLogging(): void
    {
        $examples = [
            'Concrete\'s Log facade' => "Log::addWarning('x');",
            'Concrete\'s Log facade ' => "use Log;\n",
            'error_log()' => "error_log('x');",
            'creating a logger directly' => 'Core::make(LoggerFactory::class)',
            'creating a logger directly ' => '$f->createLogger("x")',
            'another package logger' => 'new \ClassKit\Log\Logger("x")',
        ];

        foreach ($examples as $what => $code) {
            $matched = false;
            foreach (self::FORBIDDEN as $pattern) {
                $matched = $matched || preg_match($pattern, $code) === 1;
            }
            self::assertTrue($matched, $what);
        }
    }

    public function testNormalLoggingCodeIsNotFlagged(): void
    {
        $fine = '$this->logger->warning("x"); Core::make(ImporterLogger::class)->getLogger(); use Psr\Log\LoggerInterface;';

        foreach (self::FORBIDDEN as $pattern) {
            self::assertSame(0, preg_match($pattern, $fine));
        }
    }
}
