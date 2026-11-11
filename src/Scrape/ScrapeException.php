<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

use RuntimeException;

/**
 * Thrown when a sitemap or article page can not be turned into data. Messages never contain scraped content.
 */
final class ScrapeException extends RuntimeException {}
