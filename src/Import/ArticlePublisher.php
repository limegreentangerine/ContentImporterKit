<?php

declare(strict_types=1);

namespace ContentImporterKit\Import;

/**
 * Turns a sanitised article into a published page, or a new published version of an existing page.
 */
interface ArticlePublisher
{
    /**
     * @param int|null $existingPageID the page this article was published as before, to update it in place
     *
     * @throws PageAlreadyExistsException    when creating, and a page that is not the article's own is already at its address
     * @throws PublishedPageMissingException when $existingPageID no longer points to a usable page
     */
    public function publish(SanitisedArticle $article, PublishTarget $target, ?int $existingPageID): PublishResult;
}
