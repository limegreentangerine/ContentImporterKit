<?php

namespace ContentImporterKit\Command;

use Throwable;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use ContentImporterKit\Entity\ImportSource;
use ContentImporterKit\Scrape\SitemapEntry;
use Concrete\Core\Support\Facade\Application;
use ContentImporterKit\Scrape\ArticleImporterFactory;

/**
 * Imports the article of an ImportArticleCommand. Problems with the article are recorded on it by the importer, so
 * this only has to deal with a source that has been deleted since the run was queued.
 */
class ImportArticleCommandHandler
{
    public function __invoke(ImportArticleCommand $command): void
    {
        $app = Application::getFacadeApplication();
        $factory = new ArticleImporterFactory($app);
        $logger = $factory->logger();

        $source = $app->make(EntityManagerInterface::class)->find(ImportSource::class, $command->getSourceID());
        if (!$source instanceof ImportSource) {
            $logger->warning(sprintf('Article "%s" skipped: its source no longer exists.', $command->getSlug()));

            return;
        }

        $entry = new SitemapEntry($command->getUrl(), $command->getSlug(), $this->date($command->getLastModified()));
        $importer = $factory->importer($source);
        $outcome = $importer->import($entry);

        $timings = $importer->lastTimings();
        $logger->info(sprintf(
            'Source "%s", article "%s": %s (fetch %.1fs, parse %.1fs, publish %.1fs).',
            $source->getName(),
            $command->getSlug(),
            $outcome->value,
            $timings['fetch'],
            $timings['parse'],
            $timings['publish'],
        ));
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
