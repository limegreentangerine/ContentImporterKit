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

    public function find(string $slug): ?ItemState
    {
        // A copy, like a real store returns: changes only count once they are saved.
        return isset($this->items[$slug]) ? clone $this->items[$slug] : null;
    }

    public function save(ItemState $state): void
    {
        $this->items[$state->slug] = clone $state;
    }
}
