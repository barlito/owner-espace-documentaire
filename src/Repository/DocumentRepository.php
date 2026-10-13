<?php

declare(strict_types=1);

namespace App\Repository;

use App\DocumentList\DocumentFilters;
use App\DocumentList\Page;
use App\Entity\Client;
use App\Entity\Document;
use App\Enum\DocumentStatus;
use App\Supervision\TodoFilters;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
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
        return $this->countByStatus($client);
    }

    /**
     * Number of documents per status, in the order of the enum (statuses without document: 0): of one client, or of
     * all of them. A single grouped query; the supervision KPIs.
     *
     * @return list<array{status: DocumentStatus, count: int}>
     */
    public function countByStatus(?Client $client = null): array
    {
        $queryBuilder = $this->createQueryBuilder('d')
            ->select('d.status AS status', 'COUNT(d.id) AS total')
            ->groupBy('d.status')
        ;
        if (null !== $client) {
            $queryBuilder->andWhere('d.client = :client')->setParameter('client', $client);
        }

        /** @var list<array{status: DocumentStatus, total: int|string}> $rows */
        $rows = $queryBuilder->getQuery()->getResult();

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
     * One page of the supervision "À traiter" list: documents whose current attempt failed, for all clients, the
     * longest-waiting first. Client and current attempt are loaded with the documents (the rows show both).
     *
     * @return Page<Document>
     */
    public function searchFailed(TodoFilters $filters, int $page, int $perPage): Page
    {
        $total = $this->countFailed($filters);
        $number = Page::clamp($page, $total, $perPage);
        if (0 === $total) {
            return new Page([], 0, 1, $perPage);
        }

        /** @var list<Document> $items */
        $items = $this->filteredFailed($filters)
            ->join('d.client', 'c')
            ->addSelect('a', 'c')
            // A failed attempt always has a finish date; the id makes the order total, so pages never overlap.
            ->orderBy('a.finishedAt', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->setFirstResult(($number - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult()
        ;

        return new Page($items, $total, $number, $perPage);
    }

    public function countFailed(TodoFilters $filters): int
    {
        return (int) $this->filteredFailed($filters)
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * Clients that have failed documents, with how many, by name: the choices of the "client" filter.
     *
     * @return list<array{id: Uuid, name: string, count: int}>
     */
    public function failedCountsByClient(): array
    {
        /** @var list<array{id: Uuid, name: string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->join('d.client', 'c')
            ->select('c.id AS id', 'c.name AS name', 'COUNT(d.id) AS total')
            ->andWhere('d.status = :status')
            ->setParameter('status', DocumentStatus::Failed)
            ->groupBy('c.id', 'c.name')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return array_map(
            static fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'count' => (int) $row['total']],
            $rows,
        );
    }

    private function filteredFailed(TodoFilters $filters): QueryBuilder
    {
        // Served by the partial index on failed documents, whatever the size of the history.
        $queryBuilder = $this->createQueryBuilder('d')
            ->join('d.currentAttempt', 'a')
            ->andWhere('d.status = :failed')
            ->setParameter('failed', DocumentStatus::Failed)
        ;

        if (null !== $filters->clientId) {
            $queryBuilder->andWhere('IDENTITY(d.client) = :client')->setParameter('client', $filters->clientId, UuidType::NAME);
        }

        if (null !== $filters->search) {
            $queryBuilder
                ->andWhere('LOWER(UNACCENT(d.originalFilename)) LIKE LOWER(UNACCENT(:search))')
                ->setParameter('search', '%' . DocumentFilters::escapeLike($filters->search) . '%')
            ;
        }

        if (null !== $filters->reason) {
            $queryBuilder->andWhere('a.failureReason = :reason')->setParameter('reason', $filters->reason);
        }

        return $queryBuilder;
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
        $document = $this->findLocked($documentId);

        return $document?->getStatus() === $status && $document->getCurrentAttempt()->getId()->equals($attemptId) ? $document : null;
    }

    /**
     * Locks the document row whatever its state and returns it as it is in the database, or null when it does not exist.
     * For callers that decide from the state themselves (the admin retry tells "already handled" from "unknown").
     * Must run inside a transaction: the lock lasts until its end.
     */
    public function findLocked(Uuid $documentId): ?Document
    {
        $entityManager = $this->getEntityManager();

        // find() would lock an entity already in memory without reading it again: refresh it under the lock instead,
        // so that the state checked by the caller is the one in the database.
        $document = $entityManager->getUnitOfWork()->tryGetById($documentId, Document::class);
        if ($document instanceof Document) {
            $entityManager->refresh($document, LockMode::PESSIMISTIC_WRITE);

            return $document;
        }

        return $entityManager->find(Document::class, $documentId, LockMode::PESSIMISTIC_WRITE);
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
