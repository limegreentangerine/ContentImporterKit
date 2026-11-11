<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

/**
 * Keeps the ItemState of the articles of one source.
 */
interface ItemStateStore
{
    /**
     * @param string|null $hreflang the article's language; null for the source's own
     */
    public function find(string $slug, ?string $hreflang = null): ?ItemState;

    /**
     * The article that was fetched from this address, in any language. Used to find the translations of an article.
     */
    public function findByUrl(string $url): ?ItemState;

    public function save(ItemState $state): void;
}
