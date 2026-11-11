<?php

declare(strict_types=1);

namespace ContentImporterKit\Page;

use Concrete\Core\Page\Page;
use Psr\Log\LoggerInterface;
use Concrete\Core\Entity\Site\SiteTree;
use ContentImporterKit\Import\TranslationLinker;
use Concrete\Core\Multilingual\Page\Section\Section;

/**
 * Links translations with Concrete's Multilingual relations, so the language switcher finds the page in the other
 * languages.
 *
 * The page joins the relation of the translation it is linked to (and leaves its own), so a group of three that is
 * built one page at a time ends up as one relation. Concrete only knows a page by the relation it was registered in
 * when it was created, which it does by itself when the site is multilingual.
 */
final class ConcreteTranslationLinker implements TranslationLinker
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function link(int $pageID, array $translationPageIDs): void
    {
        $page = Page::getByID($pageID);
        if ($page->isError()) {
            return;
        }

        // The locale of the page's own language section, which is the language the page is in.
        $tree = $page->getSiteTreeObject();
        if (!$tree instanceof SiteTree) {
            $this->logger->warning(sprintf('Page %d was not linked to its translations: it is not in a language section.', $pageID));

            return;
        }
        $locale = $tree->getLocale()->getLocale();

        foreach ($translationPageIDs as $translationID) {
            $translation = Page::getByID($translationID);
            if ($translation->isError() || $translation->isInTrash()) {
                continue;
            }
            if ((int) Section::getRelatedCollectionIDForLocale($translationID, $locale) === $pageID) {
                continue;
            }

            Section::relatePage($translation, $page, $locale);
        }
    }
}
