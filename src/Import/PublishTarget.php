<?php

declare(strict_types=1);

namespace ContentImporterKit\Import;

/**
 * Where and how imported articles become pages.
 */
final readonly class PublishTarget
{
    /**
     * @param array<string, string> $attributeMap record attribute key => page attribute handle
     */
    public function __construct(
        public int $parentPageID,
        public string $pageTypeHandle,
        public string $pageTemplateHandle,
        public string $areaName,
        public array $attributeMap = [],
    ) {}
}
