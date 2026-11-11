<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Scrape\Support;

use ContentImporterKit\Scrape\ItemState;
use ContentImporterKit\Scrape\ItemStateStore;

/**
 * An in-memory ItemStateStore for tests.
 *
 * @internal
 */
final class FakeStore implements ItemStateStore
{
    /** @var array<string, ItemState> */
    public array $items = [];

    public function find(string $slug, ?string $hreflang = null): ?ItemState
    {
        $key = ItemState::keyOf($slug, $hreflang);

        // A copy, like a real store returns: changes only count once they are saved.
        return isset($this->items[$key]) ? clone $this->items[$key] : null;
    }

    public function findByUrl(string $url): ?ItemState
    {
        foreach ($this->items as $item) {
            if ($item->url === $url) {
                return clone $item;
            }
        }

        return null;
    }

    public function save(ItemState $state): void
    {
        $this->items[$state->key()] = clone $state;
    }
}
