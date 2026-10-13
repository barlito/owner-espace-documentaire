<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Client;
use App\Entity\Document;
use App\Enum\DocumentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
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
     * Most recent documents of a client. Status and type are plain columns: no association is loaded.
     *
     * @return list<Document>
     */
    public function findLatestForClient(Client $client, int $limit): array
    {
        return array_values($this->createQueryBuilder('d')
            ->andWhere('d.client = :client')
            ->setParameter('client', $client)
            ->orderBy('d.uploadedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult());
    }

    /**
     * Locks the document row until the end of the transaction (the messenger middleware provides it) and returns it
     * only if it is still in the state the caller expects. Serialises the handlers and the reconciliation
     * on a document, whatever order their messages arrive in.
     *
     * Always call it on a document that is not loaded yet: an already managed entity would be locked but not refreshed.
     */
    public function findForUpdate(Uuid $documentId, Uuid $attemptId, DocumentStatus $status): ?Document
    {
        $document = $this->getEntityManager()->find(Document::class, $documentId, LockMode::PESSIMISTIC_WRITE);

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
