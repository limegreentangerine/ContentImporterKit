<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Scrape\Support;

use Throwable;
use Psr\Log\AbstractLogger;

/**
 * A logger that keeps its lines, including the message of an attached exception, for assertions.
 *
 * @internal
 */
final class FakeLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, $message, array $context = [])
    {
        $exception = $context['exception'] ?? null;
        $this->lines[] = $message . ($exception instanceof Throwable ? ' | ' . $exception->getMessage() : '');
    }
}
