<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

use Throwable;
use RuntimeException;

/**
 * Thrown when a page could not be fetched. Messages never contain the URL or any of the response.
 */
final class FetchException extends RuntimeException
{
    public function __construct(string $message, private readonly int $httpStatus = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The HTTP status the server answered with, or 0 when there was no usable answer.
     */
    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
