<?php

declare(strict_types=1);

namespace ContentImporterKit\Import;

/**
 * An article whose body HTML has been through the Sanitiser.
 *
 * Anything that stores an article on a page takes this type, not ArticleRecord, so unsanitised HTML can not reach a
 * page by accident. Only Sanitiser::sanitiseRecord() should create one.
 */
final readonly class SanitisedArticle
{
    public function __construct(public ArticleRecord $record) {}

    /**
     * A fingerprint of everything that ends up on the page. Equal hashes mean re-publishing would change nothing.
     */
    public function contentHash(): string
    {
        $attributes = $this->record->attributes;
        ksort($attributes);

        return hash('sha256', json_encode([
            $this->record->title,
            $this->record->description,
            $this->record->publishedAt->format(DATE_ATOM),
            $this->record->metaDescription,
            $this->record->metaKeywords,
            $attributes,
            $this->record->bodyBlocks,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
