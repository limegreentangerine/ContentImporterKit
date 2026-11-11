<?php

declare(strict_types=1);

namespace ContentImporterKit\Page;

use Throwable;
use Concrete\Core\Page\Page;
use Psr\Log\LoggerInterface;
use Concrete\Core\Block\BlockType\BlockType;
use ContentImporterKit\Import\PublishResult;
use ContentImporterKit\Import\PublishTarget;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\Attribute\Key\CollectionKey;
use ContentImporterKit\Import\AttributeAction;
use ContentImporterKit\Import\ArticlePublisher;
use ContentImporterKit\Import\PublishException;
use ContentImporterKit\Import\SanitisedArticle;
use Concrete\Core\Page\Template as PageTemplate;
use ContentImporterKit\Import\AttributeValueResolver;
use ContentImporterKit\Import\PageAlreadyExistsException;
use ContentImporterKit\Import\PublishedPageMissingException;

/**
 * Publishes sanitised articles as Concrete pages.
 *
 * A new article becomes a page created from a draft. An article that was published before gets a new version of its
 * page, which is approved at once, so the page address, ID and history stay. On an update the "content" blocks in
 * the target area are replaced and any other blocks an editor added there are left alone.
 *
 * This class does not check permissions: it also runs from scheduled tasks, where nobody is signed in. Whoever
 * calls it (the dashboard pages, the scheduled task) is the one that has to be trusted to import.
 */
final class ConcretePagePublisher implements ArticlePublisher
{
    /** @var array<string, array{key: object, type: string}|null> */
    private array $attributeKeys = [];

    public function __construct(
        private readonly ImageImporter $images,
        private readonly LoggerInterface $logger,
        private readonly AttributeValueResolver $resolver = new AttributeValueResolver(),
    ) {}

    private function create(SanitisedArticle $article, PublishTarget $target): PublishResult
    {
        $record = $article->record;

        $parent = Page::getByID($target->parentPageID);
        if ($parent->isError()) {
            throw new PublishException('The parent page does not exist.');
        }
        $pageType = PageType::getByHandle($target->pageTypeHandle);
        if (!is_object($pageType)) {
            throw new PublishException('The page type does not exist.');
        }
        $template = PageTemplate::getByHandle($target->pageTemplateHandle);
        if (!is_object($template)) {
            throw new PublishException('The page template does not exist.');
        }

        if (!Page::getByPath($parent->getCollectionPath() . '/' . $record->urlSlug)->isError()) {
            throw new PageAlreadyExistsException('A page already exists at the address of this article.');
        }

        $draft = null;
        try {
            $draft = $pageType->createDraft($template);
            $draft->setPageDraftTargetParentPageID($parent->getCollectionID());
            $draft->update(['cHandle' => $record->urlSlug] + $this->pageData($article));
            $this->fill($draft, $article, $target, false);
            $pageType->publish($draft, false, false);
        } catch (Throwable $e) {
            // Don't leave a half-built draft behind.
            if ($draft !== null) {
                try {
                    $draft->delete();
                } catch (Throwable) {
                }
            }

            throw $e;
        }

        return new PublishResult((int) $draft->getCollectionID(), true);
    }

    private function update(SanitisedArticle $article, PublishTarget $target, int $pageID): PublishResult
    {
        $page = Page::getByID($pageID);
        if ($page->isError() || $page->isInTrash() || (int) $page->getCollectionID() !== $pageID) {
            throw new PublishedPageMissingException('The page this article was imported as no longer exists.');
        }

        $version = $page->getVersionToModify();
        try {
            $version->update($this->pageData($article));
            $this->fill($version, $article, $target, true);
            $version->getVersionObject()->approve();
        } catch (Throwable $e) {
            // The page keeps its current version; only the unfinished new one is dropped.
            try {
                $version->getVersionObject()->delete();
            } catch (Throwable) {
            }

            throw $e;
        }

        return new PublishResult($pageID, false);
    }

    /**
     * @return array<string, string>
     */
    private function pageData(SanitisedArticle $article): array
    {
        $record = $article->record;

        return [
            'cName' => $record->title,
            'cDescription' => $record->description,
            'cDatePublic' => $record->publishedAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Sets the mapped attributes and fills the target area with the article's content.
     */
    private function fill(Page $page, SanitisedArticle $article, PublishTarget $target, bool $replaceBlocks): void
    {
        foreach ($target->attributeMap as $recordKey => $handle) {
            $value = $article->record->attributes[$recordKey] ?? '';
            if ($value !== '') {
                $this->setAttribute($page, $handle, $recordKey, $value);
            }
        }

        $blockType = BlockType::getByHandle('content');
        if (!is_object($blockType)) {
            throw new PublishException('The Content block type is not installed.');
        }

        if ($replaceBlocks) {
            foreach ($page->getBlocks($target->areaName) as $block) {
                if ($block->getBlockTypeHandle() === 'content') {
                    $block->delete();
                }
            }
        }

        foreach ($article->record->bodyBlocks as $block) {
            $page->addBlock($blockType, $target->areaName, ['content' => $block['data']]);
        }
    }

    private function setAttribute(Page $page, string $handle, string $recordKey, string $value): void
    {
        $attribute = $this->attribute($handle);
        if ($attribute === null) {
            $this->logger->debug(sprintf('Value of "%s" skipped: the page attribute "%s" does not exist.', $recordKey, $handle));

            return;
        }

        switch ($this->resolver->decide($value, $attribute['type'])) {
            case AttributeAction::StoreText:
                $page->setAttribute($attribute['key'], $value);

                return;
            case AttributeAction::DownloadImage:
                $fileID = $this->images->import($value);
                if ($fileID !== null) {
                    $page->setAttribute($attribute['key'], $fileID);
                }

                return;
            case AttributeAction::Skip:
                $this->logger->debug(sprintf('Value of "%s" skipped: not usable for the attribute "%s".', $recordKey, $handle));

                return;
        }
    }

    /**
     * @return array{key: object, type: string}|null
     */
    private function attribute(string $handle): ?array
    {
        if (!array_key_exists($handle, $this->attributeKeys)) {
            $key = CollectionKey::getByHandle($handle);
            $this->attributeKeys[$handle] = is_object($key)
                ? ['key' => $key, 'type' => (string) $key->getAttributeType()->getAttributeTypeHandle()]
                : null;
        }

        return $this->attributeKeys[$handle];
    }

    public function publish(SanitisedArticle $article, PublishTarget $target, ?int $existingPageID): PublishResult
    {
        return $existingPageID === null
            ? $this->create($article, $target)
            : $this->update($article, $target, $existingPageID);
    }
}
