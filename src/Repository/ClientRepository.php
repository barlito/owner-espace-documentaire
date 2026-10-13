<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Client;
use App\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Client>
 */
class ClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Client::class);
    }

    /**
     * Clients with their number of documents, in a single query.
     *
     * @return list<array{client: Client, documentCount: int}>
     */
    public function findAllWithDocumentCount(): array
    {
        /** @var list<array{0: Client, documentCount: numeric-string|int}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c', 'COUNT(d.id) AS documentCount')
            ->leftJoin(Document::class, 'd', 'WITH', 'd.client = c')
            ->groupBy('c.id')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return array_map(
            static fn (array $row): array => ['client' => $row[0], 'documentCount' => (int) $row['documentCount']],
            $rows,
        );
    }
}
