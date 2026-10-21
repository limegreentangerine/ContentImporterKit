<?php

declare(strict_types=1);

namespace ContentImporterKit\Import;

use RuntimeException;

/**
 * The page an article was published as has been deleted, or can no longer be updated.
 */
final class PublishedPageMissingException extends RuntimeException {}
