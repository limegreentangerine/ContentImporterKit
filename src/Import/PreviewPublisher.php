<?php

declare(strict_types=1);

namespace ContentImporterKit\Import;

/**
 * Publishes nothing. It answers as a real publisher would, so a preview shows whether an article would be created or
 * updated without making a page, a version or an image.
 */
final class PreviewPublisher implements ArticlePublisher
{
    public function publish(SanitisedArticle $article, PublishTarget $target, ?int $existingPageID): PublishResult
    {
        // Nothing is created, so a new article has no page ID to report.
        return new PublishResult($existingPageID ?? 0, $existingPageID === null);
    }
}
