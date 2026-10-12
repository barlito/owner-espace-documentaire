<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Client;
use App\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
