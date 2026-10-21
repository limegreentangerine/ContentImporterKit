<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

use Doctrine\ORM\EntityManagerInterface;
use ContentImporterKit\Entity\ImportedItem;
use ContentImporterKit\Entity\ImportSource;

/**
 * Keeps the ItemState of one source's articles in the ciItems table.
 */
final class DoctrineItemStore implements ItemStateStore
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ImportSource $source,
    ) {}

    private function findItem(string $slug): ?ImportedItem
    {
        $item = $this->entityManager->getRepository(ImportedItem::class)->findOneBy([
            'source' => $this->source,
            'slug' => $slug,
        ]);

        return $item instanceof ImportedItem ? $item : null;
    }

    public function find(string $slug): ?ItemState
    {
        $item = $this->findItem($slug);

        return $item === null ? null : new ItemState(
            $item->getSlug(),
            $item->getUrl(),
            $item->getPageID(),
            $item->getEtag(),
            $item->getLastModified(),
            $item->getRemoteLastmod(),
            $item->getContentHash(),
            $item->getStatus(),
            $item->getError(),
        );
    }

    public function save(ItemState $state): void
    {
        $item = $this->findItem($state->slug) ?? new ImportedItem($this->source, $state->slug);

        $item
            ->setUrl($state->url)
            ->setPageID($state->pageID)
            ->setEtag($state->etag)
            ->setLastModified($state->lastModified)
            ->setRemoteLastmod($state->remoteLastmod)
            ->setContentHash($state->contentHash)
            ->setStatus($state->status)
            ->setError($state->error);

        // The entity's own timestamp only moves when something changed, and the history should show every attempt.
        $item->setDateUpdated(new \DateTimeImmutable());

        $this->entityManager->persist($item);
        // Flushed straight away: a queue worker can stop at any point, and an imported page must never be forgotten.
        $this->entityManager->flush();
    }
}
