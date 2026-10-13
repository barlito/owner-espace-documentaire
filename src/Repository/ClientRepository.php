<?php

declare(strict_types=1);

namespace App\Repository;

use App\DocumentList\Page;
use App\DocumentList\SearchTerm;
use App\Entity\Client;
use App\Entity\Document;
use App\Enum\DocumentStatus;
use App\Supervision\ClientOverview;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
     * One page of the clients page: clients whose name matches, the ones with the most failed documents first,
     * each with its documents per status. Two queries whatever the page size: the page itself, then one grouped count
     * for the clients on it.
     *
     * @return Page<ClientOverview>
     */
    public function searchWithStatusCounts(?string $search, int $page, int $perPage): Page
    {
        $total = (int) $this->withNameLike($search)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
        $number = Page::clamp($page, $total, $perPage);
        if (0 === $total) {
            return new Page([], 0, 1, $perPage);
        }

        /** @var list<Client> $clients */
        $clients = $this->withNameLike($search)
            // Failures are counted per client through idx_document_client_status, only to sort (not selected).
            ->addSelect(\sprintf('(SELECT COUNT(f.id) FROM %s f WHERE f.client = c AND f.status = :failed) AS HIDDEN failures', Document::class))
            ->setParameter('failed', DocumentStatus::Failed)
            ->orderBy('failures', 'DESC')
            ->addOrderBy('c.name', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->setFirstResult(($number - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult()
        ;

        $counts = $this->statusCounts($clients);

        return new Page(
            array_map(static fn (Client $client): ClientOverview => new ClientOverview($client, $counts[$client->getId()->toRfc4122()] ?? []), $clients),
            $total,
            $number,
            $perPage,
        );
    }

    private function withNameLike(?string $search): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('c');
        if (null !== $search) {
            // Case and accent insensitive on both sides, like the document search.
            $queryBuilder
                ->andWhere('LOWER(UNACCENT(c.name)) LIKE LOWER(UNACCENT(:search))')
                ->setParameter('search', '%' . SearchTerm::escapeLike($search) . '%')
            ;
        }

        return $queryBuilder;
    }

    /**
     * @param list<Client> $clients
     *
     * @return array<string, array<string, int>> status counts by client id (RFC 4122), then by status value
     */
    private function statusCounts(array $clients): array
    {
        if ([] === $clients) {
            return [];
        }

        /** @var list<array{clientId: string, status: DocumentStatus, total: int|string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(d.client) AS clientId', 'd.status AS status', 'COUNT(d.id) AS total')
            ->from(Document::class, 'd')
            ->andWhere('d.client IN (:clients)')
            ->setParameter('clients', $clients)
            ->groupBy('d.client', 'd.status')
            ->getQuery()
            ->getResult()
        ;

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['clientId']][$row['status']->value] = (int) $row['total'];
        }

        return $counts;
    }
}
