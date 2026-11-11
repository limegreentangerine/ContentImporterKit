<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

/**
 * Reads what a source remembers from another store, but keeps whatever is saved in memory instead of passing it on,
 * so a preview can run the importer without changing the history of the source.
 */
final class PreviewStore implements ItemStateStore
{
    /** @var array<string, ItemState> */
    private array $saved = [];

    public function __construct(private readonly ItemStateStore $real) {}

    public function find(string $slug, ?string $hreflang = null): ?ItemState
    {
        return $this->real->find($slug, $hreflang);
    }

    public function findByUrl(string $url): ?ItemState
    {
        return $this->real->findByUrl($url);
    }

    public function save(ItemState $state): void
    {
        $this->saved[$state->key()] = clone $state;
    }

    /**
     * What the importer would have saved for this article.
     */
    public function saved(string $slug, ?string $hreflang = null): ?ItemState
    {
        return $this->saved[ItemState::keyOf($slug, $hreflang)] ?? null;
    }
}
