<?php

namespace Concrete\Package\ContentImporterKit\Controller\SinglePage\Dashboard\ContentImporter;

use Doctrine\ORM\EntityManagerInterface;
use ContentImporterKit\Entity\ImportedItem;
use ContentImporterKit\Entity\ImportSource;
use Concrete\Core\Page\Controller\DashboardPageController;

class Activity extends DashboardPageController
{
    private const LIMIT = 200;

    private const STATUSES = ['imported', 'updated', 'unchanged', 'failed'];

    public function view()
    {
        $entityManager = $this->app->make(EntityManagerInterface::class);

        $sourceID = (int) $this->request->query->get('source', 0);
        $status = (string) $this->request->query->get('status', '');
        if (!in_array($status, self::STATUSES, true)) {
            $status = '';
        }

        $query = $entityManager->createQueryBuilder()
            ->select('i', 's')
            ->from(ImportedItem::class, 'i')
            ->join('i.source', 's')
            ->orderBy('i.date_updated', 'DESC')
            ->setMaxResults(self::LIMIT);

        if ($sourceID > 0) {
            $query->andWhere('s.id = :source')->setParameter('source', $sourceID);
        }
        if ($status !== '') {
            $query->andWhere('i.status = :status')->setParameter('status', $status);
        }

        $sources = ['' => t('All sources')];
        foreach ($entityManager->getRepository(ImportSource::class)->findBy([], ['name' => 'ASC']) as $source) {
            $sources[(string) $source->getID()] = $source->getName();
        }

        $this->set('items', $query->getQuery()->getResult());
        $this->set('sources', $sources);
        $this->set('sourceID', $sourceID > 0 ? (string) $sourceID : '');
        $this->set('status', $status);
        $this->set('limit', self::LIMIT);
    }
}
