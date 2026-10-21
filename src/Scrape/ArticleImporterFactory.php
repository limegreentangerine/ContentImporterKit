<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

use Throwable;
use Concrete\Core\User\User;
use Psr\Log\LoggerInterface;
use GuzzleHttp\ClientInterface;
use Concrete\Core\File\Importer;
use ContentImporterKit\Import\Sanitiser;
use Doctrine\ORM\EntityManagerInterface;
use ContentImporterKit\Security\UrlGuard;
use Concrete\Core\Application\Application;
use Concrete\Core\File\Set\Set as FileSet;
use ContentImporterKit\Log\ImporterLogger;
use ContentImporterKit\Page\ImageImporter;
use ContentImporterKit\Entity\ImportSource;
use ContentImporterKit\Page\ConcreteImageSink;
use ContentImporterKit\Page\ConcretePagePublisher;

/**
 * Builds the importer and its helpers from Concrete's services. It is the one place that knows how the pieces fit
 * together, so the scheduled task, the queue handler and the dashboard pages all get the same safe set-up: every
 * fetch checked by UrlGuard, every body sanitised, everything logged through ImporterLogger.
 */
final class ArticleImporterFactory
{
    private const MAX_PAGE_BYTES = 5_000_000;

    private const MAX_IMAGE_BYTES = 10_000_000;

    public function __construct(private readonly Application $app) {}

    private function store(ImportSource $source): DoctrineItemStore
    {
        return new DoctrineItemStore($this->app->make(EntityManagerInterface::class), $source);
    }

    /**
     * The signed-in user, or 0 when nobody is (scheduled tasks and queue workers).
     */
    private function currentUserID(): int
    {
        try {
            return (int) $this->app->make(User::class)->getUserID();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * The package logger. Everything the package logs goes through it.
     */
    public function logger(): LoggerInterface
    {
        return $this->app->make(ImporterLogger::class)->getLogger();
    }

    public function sanitiser(): Sanitiser
    {
        $cacheDirectory = rtrim((string) $this->app->make('config')->get('concrete.cache.directory'), '/');

        return new Sanitiser($cacheDirectory . '/content_importer_kit/purifier');
    }

    public function fetcher(int $maxBytes = self::MAX_PAGE_BYTES): Fetcher
    {
        /** @var ClientInterface $client */
        $client = $this->app->make('http/client');

        return new Fetcher($client, new UrlGuard(), maxBytes: $maxBytes);
    }

    public function planner(ImportSource $source): ArticlePlanner
    {
        return new ArticlePlanner($this->fetcher(), new SitemapReader(), $this->store($source));
    }

    public function importer(ImportSource $source): ArticleImporter
    {
        return new ArticleImporter(
            $this->fetcher(),
            new ArticleParser(),
            $this->sanitiser(),
            $this->publisher(),
            $this->store($source),
            $source->toSourceConfig(),
            $source->toPublishTarget(),
            $this->logger(),
        );
    }

    /**
     * The publisher is shared by the scraper and the JSON upload, so both create pages the same way.
     */
    public function publisher(): ConcretePagePublisher
    {
        $logger = $this->logger();
        $fileSet = FileSet::createAndGetSet('Imported Images', FileSet::TYPE_PUBLIC, $this->currentUserID());
        $sink = new ConcreteImageSink($this->app->make(Importer::class), $fileSet);

        $temporary = $this->app->make('helper/file')->getTemporaryDirectory();
        $images = new ImageImporter(
            $this->fetcher(self::MAX_IMAGE_BYTES),
            $sink,
            $logger,
            is_string($temporary) && $temporary !== '' ? $temporary : null,
        );

        return new ConcretePagePublisher($images, $logger);
    }
}
