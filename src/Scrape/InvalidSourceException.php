<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

use InvalidArgumentException;

/**
 * Thrown when the settings of a scrape source are not usable. Messages name the field, never the value.
 */
final class InvalidSourceException extends InvalidArgumentException {}
