<?php

declare(strict_types=1);

namespace ContentImporterKit\Import;

use RuntimeException;

/**
 * A page that is not the article's own already exists at the address the article would get.
 */
final class PageAlreadyExistsException extends RuntimeException {}
