<?php

declare(strict_types=1);

namespace ContentImporterKit\Import;

use RuntimeException;

/**
 * Thrown when an import file or one of its records does not match the expected shape.
 * Messages describe the field, never the offending value, so they are safe to show.
 */
final class InvalidArticleException extends RuntimeException {}
