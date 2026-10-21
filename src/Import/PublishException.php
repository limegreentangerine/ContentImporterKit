<?php

declare(strict_types=1);

namespace ContentImporterKit\Import;

use RuntimeException;

/**
 * An article could not be published because of how the site is set up (a missing parent page, page type or
 * template). Messages describe the setting and never contain article content, so they are safe to show.
 */
final class PublishException extends RuntimeException {}
