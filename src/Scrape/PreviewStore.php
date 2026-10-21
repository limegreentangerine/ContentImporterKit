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

    public function find(string $slug): ?ItemState
    {
        return $this->real->find($slug);
    }

    public function save(ItemState $state): void
    {
        $this->saved[$state->slug] = clone $state;
    }

    /**
     * What the importer would have saved for this article.
     */
    public function saved(string $slug): ?ItemState
    {
        return $this->saved[$slug] ?? null;
    }
}
