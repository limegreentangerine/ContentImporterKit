<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

use InvalidArgumentException;

/**
 * Thrown when a CSS selector uses syntax the scraper does not support.
 */
final class InvalidSelectorException extends InvalidArgumentException {}
