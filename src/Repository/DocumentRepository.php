<?php

declare(strict_types=1);

namespace App\Repository;

use App\DocumentList\DocumentFilters;
use App\DocumentList\Page;
use App\Entity\Client;
use App\Entity\Document;
use App\Enum\DocumentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Document>
 */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * Client-scoped lookup: a document of another client is indistinguishable from a missing one.
     */
    public function findOneForClient(Uuid $id, Client $client): ?Document
    {
        return $this->findOneBy(['id' => $id, 'client' => $client]);
    }

    /**
     * One page of "Mon dossier": the client's documents matching the filters, most recent first.
     * Documents are loaded without any association (the row only reads scalar columns); an out-of-range page
     * returns the last one.
     *
     * @return Page<Document>
     */
    public function searchForClient(Client $client, DocumentFilters $filters, int $page, int $perPage): Page
    {
        $total = $this->countForClient($client, $filters);
        $number = Page::clamp($page, $total, $perPage);
        if (0 === $total) {
            return new Page([], 0, 1, $perPage);
        }

        /** @var list<Document> $items */
        $items = $this->filteredForClient($client, $filters)
            ->orderBy('d.uploadedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setFirstResult(($number - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult()
        ;

        return new Page($items, $total, $number, $perPage);
    }

    public function countForClient(Client $client, DocumentFilters $filters): int
    {
        return (int) $this->filteredForClient($client, $filters)
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * Number of documents of the client per status, in the order of the enum (statuses without document: 0).
     *
     * @return list<array{status: DocumentStatus, count: int}>
     */
    public function countByStatusForClient(Client $client): array
    {
        /** @var list<array{status: DocumentStatus, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.status AS status', 'COUNT(d.id) AS total')
            ->andWhere('d.client = :client')
            ->setParameter('client', $client)
            ->groupBy('d.status')
            ->getQuery()
            ->getResult()
        ;

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['total'];
        }

        return array_map(
            static fn (DocumentStatus $status): array => ['status' => $status, 'count' => $counts[$status->value] ?? 0],
            DocumentStatus::cases(),
        );
    }

    /**
     * The most recent months ("YYYY-MM", most recent first) in which one of the client's documents starts: the
     * months worth offering in the period filter.
     *
     * @return list<string>
     */
    public function periodMonthsForClient(Client $client, int $limit): array
    {
        /** @var list<string> $months */
        $months = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT DISTINCT to_char(period_start, 'YYYY-MM') AS month FROM document WHERE client_id = :client AND period_start IS NOT NULL ORDER BY month DESC LIMIT :limit",
            ['client' => $client->getId()->toRfc4122(), 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return $months;
    }

    /**
     * The client condition comes first and is the only way in: filters can only narrow it down.
     */
    private function filteredForClient(Client $client, DocumentFilters $filters): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('d')
            ->andWhere('d.client = :client')
            ->setParameter('client', $client)
        ;

        if (null !== $filters->search) {
            // Case and accent insensitive on both sides, so "releve" finds "Relevé" and conversely.
            $queryBuilder
                ->andWhere('LOWER(UNACCENT(d.originalFilename)) LIKE LOWER(UNACCENT(:search))')
                ->setParameter('search', '%' . DocumentFilters::escapeLike($filters->search) . '%')
            ;
        }

        if (null !== $filters->status) {
            $queryBuilder->andWhere('d.status = :status')->setParameter('status', $filters->status);
        }

        if ($filters->uncategorized) {
            $queryBuilder->andWhere('d.type IS NULL');
        } elseif (null !== $filters->type) {
            $queryBuilder->andWhere('d.type = :type')->setParameter('type', $filters->type);
        }

        if (null !== $filters->period) {
            // Overlap, not inclusion: a quarterly statement matches each of its months. A document without
            // period has NULL bounds, which never satisfy the comparisons.
            $queryBuilder
                ->andWhere('d.periodStart <= :periodEnd')
                ->andWhere('d.periodEnd >= :periodStart')
                ->setParameter('periodStart', $filters->period->start, Types::DATE_IMMUTABLE)
                ->setParameter('periodEnd', $filters->period->end, Types::DATE_IMMUTABLE)
            ;
        }

        return $queryBuilder;
    }

    /**
     * Locks the document row until the end of the transaction (the messenger middleware provides it) and returns it
     * only if it is still in the state the caller expects. Serialises the handlers and the reconciliation
     * on a document, whatever order their messages arrive in.
     */
    public function findForUpdate(Uuid $documentId, Uuid $attemptId, DocumentStatus $status): ?Document
    {
        $entityManager = $this->getEntityManager();

        // find() would lock an entity already in memory without reading it again: refresh it under the lock instead,
        // so that the state checked below is the one in the database.
        $document = $entityManager->getUnitOfWork()->tryGetById($documentId, Document::class);
        if ($document instanceof Document) {
            $entityManager->refresh($document, LockMode::PESSIMISTIC_WRITE);
        } else {
            $document = $entityManager->find(Document::class, $documentId, LockMode::PESSIMISTIC_WRITE);
        }

        return $document?->getStatus() === $status && $document->getCurrentAttempt()->getId()->equals($attemptId) ? $document : null;
    }

    /**
     * Processing attempts started before the threshold, oldest first.
     *
     * @return list<array{documentId: Uuid, attemptId: Uuid}>
     */
    public function findStaleProcessing(\DateTimeImmutable $startedBefore, int $limit): array
    {
        return $this->identifiers($this->createQueryBuilder('d')
            ->join('d.currentAttempt', 'a')
            ->andWhere('d.status = :status')
            ->andWhere('a.startedAt < :threshold')
            ->setParameter('status', DocumentStatus::Processing)
            ->setParameter('threshold', $startedBefore, Types::DATETIMETZ_IMMUTABLE)
            ->orderBy('a.startedAt', 'ASC')
            ->setMaxResults($limit));
    }

    /**
     * Pending attempts whose message was never sent, oldest first.
     *
     * @return list<array{documentId: Uuid, attemptId: Uuid}>
     */
    public function findNeverQueued(int $limit): array
    {
        return $this->identifiers($this->createQueryBuilder('d')
            ->join('d.currentAttempt', 'a')
            ->andWhere('d.status = :status')
            ->andWhere('a.queuedAt IS NULL')
            ->setParameter('status', DocumentStatus::Pending)
            ->orderBy('a.requestedAt', 'ASC')
            ->setMaxResults($limit));
    }

    /**
     * Only identifiers are selected: the documents are loaded (and locked) one by one by findForUpdate().
     *
     * @return list<array{documentId: Uuid, attemptId: Uuid}>
     */
    private function identifiers(QueryBuilder $queryBuilder): array
    {
        /** @var list<array{documentId: Uuid, attemptId: Uuid}> $rows */
        $rows = $queryBuilder->select('d.id AS documentId', 'a.id AS attemptId')->getQuery()->getResult();

        return $rows;
    }
}
