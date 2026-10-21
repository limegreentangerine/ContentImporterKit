<?php

namespace ContentImporterKit\Command\Task\Controller;

use Throwable;
use DateTimeImmutable;
use Concrete\Core\Command\Batch\Batch;
use Doctrine\ORM\EntityManagerInterface;
use Concrete\Core\Application\Application;
use ContentImporterKit\Entity\ImportSource;
use Concrete\Core\Command\Task\TaskInterface;
use Concrete\Core\Command\Task\Input\InputInterface;
use ContentImporterKit\Command\ImportArticleCommand;
use ContentImporterKit\Scrape\ArticleImporterFactory;
use Concrete\Core\Command\Task\Runner\TaskRunnerInterface;
use Concrete\Core\Command\Task\Controller\AbstractController;
use Concrete\Core\Command\Task\Runner\BatchProcessTaskRunner;

class ImportSourcesController extends AbstractController
{
    public function __construct(private readonly Application $app) {}

    public function getName(): string
    {
        return t('Import News Sources');
    }

    public function getDescription(): string
    {
        return t('Reads the sitemap of every enabled source and imports its new and changed articles as pages.');
    }

    public function getTaskRunner(TaskInterface $task, InputInterface $input): TaskRunnerInterface
    {
        $factory = new ArticleImporterFactory($this->app);
        $logger = $factory->logger();
        $sources = $this->app->make(EntityManagerInterface::class)
            ->getRepository(ImportSource::class)
            ->findBy(['enabled' => 1], ['id' => 'ASC']);

        $batch = Batch::create(t('Import news articles'));

        foreach ($sources as $source) {
            $started = microtime(true);

            try {
                $plan = $factory->planner($source)->plan($source->toSourceConfig(), new DateTimeImmutable());
            } catch (Throwable $e) {
                // One source being down or mis-set must not stop the others.
                $logger->error(sprintf('Source "%s" skipped: %s', $source->getName(), $e->getMessage()));

                continue;
            }

            foreach ($plan->entries as $entry) {
                $batch->add(new ImportArticleCommand(
                    $source->getID(),
                    $entry->url,
                    $entry->slug,
                    $entry->lastModified?->format(DATE_ATOM),
                ));
            }

            $logger->info(sprintf(
                'Source "%s": %d to import, %d already up to date, %d not eligible, %d left for a later run (planned in %.1fs).',
                $source->getName(),
                count($plan->entries),
                $plan->upToDate,
                $plan->notEligible,
                $plan->beyondLimit,
                microtime(true) - $started,
            ));
        }

        return new BatchProcessTaskRunner($task, $batch, $input, t('Importing news articles...'));
    }
}
