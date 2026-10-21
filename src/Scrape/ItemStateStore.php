<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

/**
 * Keeps the ItemState of the articles of one source.
 */
interface ItemStateStore
{
    public function find(string $slug): ?ItemState;

    public function save(ItemState $state): void;
}
