<?php

declare(strict_types=1);

namespace ContentImporterKit\Security;

use RuntimeException;

/**
 * Thrown when a URL must not be fetched server-side. The message never contains the URL, so it is safe to show.
 */
final class UnsafeUrlException extends RuntimeException {}
