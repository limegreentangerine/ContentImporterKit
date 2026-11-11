<?php

declare(strict_types=1);

namespace ContentImporterKit\Import;

/**
 * Tells the site that pages are translations of each other.
 */
interface TranslationLinker
{
    /**
     * Links a page to the pages that are its translations. It does nothing for a page that is already linked, and
     * for pages that can not be linked (the site is not multilingual, or the page is outside a language section).
     *
     * @param list<int> $translationPageIDs
     */
    public function link(int $pageID, array $translationPageIDs): void;
}
