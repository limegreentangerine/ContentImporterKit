<?php

declare(strict_types=1);

namespace ContentImporterKit\Import;

/**
 * What happened when an article was published.
 */
final readonly class PublishResult
{
    public function __construct(
        public int $pageID,
        public bool $created,
    ) {}
}
