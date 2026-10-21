<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

use Throwable;
use Psr\Log\LoggerInterface;
use ContentImporterKit\Import\Sanitiser;
use ContentImporterKit\Import\ArticleRecord;
use ContentImporterKit\Import\PublishResult;
use ContentImporterKit\Import\PublishTarget;
use ContentImporterKit\Import\ArticlePublisher;
use ContentImporterKit\Import\PublishException;
use ContentImporterKit\Import\SanitisedArticle;
use ContentImporterKit\Import\InvalidArticleException;
use ContentImporterKit\Import\PageAlreadyExistsException;
use ContentImporterKit\Import\PublishedPageMissingException;

/**
 * Imports one article of a scrape source as a page, or updates the page it was imported as.
 *
 * It does as little work as it can:
 *  - the sitemap says the article has not changed since the last import: no request at all;
 *  - the server answers a conditional request with 304: nothing is parsed;
 *  - the cleaned article is identical to the last import: the page is not touched.
 *
 * A problem with one article is recorded on its ItemState and reported as ImportOutcome::Failed. It never stops the
 * run, so one bad page can not hold up the others.
 */
final class ArticleImporter
{
    private const MAX_ERROR_LENGTH = 500;

    /** @var array{fetch: float, parse: float, publish: float} seconds spent in each step of the last import */
    private array $timings = ['fetch' => 0.0, 'parse' => 0.0, 'publish' => 0.0];

    public function __construct(
        private readonly Fetcher $fetcher,
        private readonly ArticleParser $parser,
        private readonly Sanitiser $sanitiser,
        private readonly ArticlePublisher $publisher,
        private readonly ItemStateStore $store,
        private readonly SourceConfig $config,
        private readonly PublishTarget $target,
        private readonly LoggerInterface $logger,
    ) {}

    private function run(ItemState $state, SitemapEntry $entry): ImportOutcome
    {
        $headers = [];
        if ($state->pageID !== null) {
            if ($state->etag !== null) {
                $headers['If-None-Match'] = $state->etag;
            }
            if ($state->lastModified !== null) {
                $headers['If-Modified-Since'] = $state->lastModified;
            }
        }

        $started = microtime(true);
        $fetched = $this->fetcher->get($entry->url, $headers);
        $this->timings['fetch'] = microtime(true) - $started;

        if ($fetched->notModified) {
            return $this->unchanged($state, $entry, $fetched->etag ?? $state->etag, $fetched->lastModified ?? $state->lastModified);
        }

        $started = microtime(true);
        $raw = $this->parser->parse($fetched->body, $fetched->url, $entry->slug, $this->config, $entry->lastModified);
        $article = $this->sanitiser->sanitiseRecord(ArticleRecord::fromArray($raw));
        $hash = $article->contentHash();

        if ($state->pageID !== null && $state->contentHash === $hash) {
            $this->timings['parse'] = microtime(true) - $started;

            return $this->unchanged($state, $entry, $fetched->etag, $fetched->lastModified);
        }

        $this->timings['parse'] = microtime(true) - $started;

        $started = microtime(true);
        $result = $this->publish($article, $state);
        $this->timings['publish'] = microtime(true) - $started;

        $state->pageID = $result->pageID;
        $state->contentHash = $hash;
        $state->etag = $fetched->etag;
        $state->lastModified = $fetched->lastModified;
        $state->remoteLastmod = $entry->lastModified;
        $state->status = $result->created ? 'imported' : 'updated';
        $state->error = null;
        $this->store->save($state);

        return $result->created ? ImportOutcome::Imported : ImportOutcome::Updated;
    }

    private function publish(SanitisedArticle $article, ItemState $state): PublishResult
    {
        try {
            return $this->publisher->publish($article, $this->target, $state->pageID);
        } catch (PublishedPageMissingException) {
            // The page was deleted in Concrete since the last import: make a new one.
            $state->pageID = null;

            return $this->publisher->publish($article, $this->target, null);
        }
    }

    private function unchanged(ItemState $state, SitemapEntry $entry, ?string $etag, ?string $lastModified): ImportOutcome
    {
        $state->etag = $etag;
        $state->lastModified = $lastModified;
        $state->remoteLastmod = $entry->lastModified;
        $state->status = 'unchanged';
        $state->error = null;
        $this->store->save($state);

        return ImportOutcome::Unchanged;
    }

    private function describe(Throwable $e): string
    {
        // Exceptions from this package carry messages that are safe to show. For anything else only the class is
        // shown, because it may carry paths or SQL; the full detail goes to the log.
        $safe = $e instanceof FetchException
            || $e instanceof ScrapeException
            || $e instanceof InvalidArticleException
            || $e instanceof PublishException
            || $e instanceof PageAlreadyExistsException;

        $message = $safe ? $e->getMessage() : sprintf('Unexpected error (%s). See the log for details.', $e::class);

        return mb_substr($message, 0, self::MAX_ERROR_LENGTH);
    }

    /**
     * Seconds spent fetching, parsing and publishing in the last call to import(), for logging. A step that did not
     * happen (nothing to fetch, nothing to publish) is 0.
     *
     * @return array{fetch: float, parse: float, publish: float}
     */
    public function lastTimings(): array
    {
        return $this->timings;
    }

    public function import(SitemapEntry $entry): ImportOutcome
    {
        $this->timings = ['fetch' => 0.0, 'parse' => 0.0, 'publish' => 0.0];

        $state = $this->store->find($entry->slug);

        if ($state !== null && $state->isCurrentFor($entry)) {
            return ImportOutcome::Unchanged;
        }

        $state ??= new ItemState($entry->slug, $entry->url);
        $state->url = $entry->url;

        try {
            return $this->run($state, $entry);
        } catch (Throwable $e) {
            $state->status = 'failed';
            $state->error = $this->describe($e);
            $this->store->save($state);
            $this->logger->warning(sprintf('Import of "%s" failed: %s', $entry->slug, $e->getMessage()), ['exception' => $e]);

            return ImportOutcome::Failed;
        }
    }
}
