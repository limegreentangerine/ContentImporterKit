<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Scrape\Support;

use Throwable;
use ContentImporterKit\Import\PublishResult;
use ContentImporterKit\Import\PublishTarget;
use ContentImporterKit\Import\ArticlePublisher;
use ContentImporterKit\Import\SanitisedArticle;
use ContentImporterKit\Import\PageAlreadyExistsException;
use ContentImporterKit\Import\PublishedPageMissingException;

/**
 * An ArticlePublisher that records what it was asked to publish, and can be told to fail.
 *
 * @internal
 */
final class FakePublisher implements ArticlePublisher
{
    private int $nextPageID = 101;
    /** @var list<array{article: SanitisedArticle, target: PublishTarget, existingPageID: ?int}> */
    public array $calls = [];

    /** The next update finds its page gone (once). */
    public bool $missing = false;

    /** Creating finds a page that is not ours at the address. */
    public bool $exists = false;

    public ?Throwable $throw = null;

    public function publish(SanitisedArticle $article, PublishTarget $target, ?int $existingPageID): PublishResult
    {
        $this->calls[] = ['article' => $article, 'target' => $target, 'existingPageID' => $existingPageID];

        if ($this->throw !== null) {
            throw $this->throw;
        }
        if ($existingPageID !== null && $this->missing) {
            $this->missing = false;
            throw new PublishedPageMissingException('gone');
        }
        if ($existingPageID === null && $this->exists) {
            throw new PageAlreadyExistsException('A page already exists at the address of this article.');
        }

        return $existingPageID !== null
            ? new PublishResult($existingPageID, false)
            : new PublishResult($this->nextPageID++, true);
    }
}
