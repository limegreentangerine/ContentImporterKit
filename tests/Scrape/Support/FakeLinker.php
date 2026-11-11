<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Scrape\Support;

use RuntimeException;
use ContentImporterKit\Import\TranslationLinker;

/**
 * A TranslationLinker that records what it was asked to link, and can be told to fail.
 *
 * @internal
 */
final class FakeLinker implements TranslationLinker
{
    /** @var list<array{pageID: int, translations: list<int>}> */
    public array $calls = [];

    public bool $fail = false;

    public function link(int $pageID, array $translationPageIDs): void
    {
        $this->calls[] = ['pageID' => $pageID, 'translations' => $translationPageIDs];

        if ($this->fail) {
            throw new RuntimeException('no relation');
        }
    }
}
