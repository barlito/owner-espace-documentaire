<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentStatus;
use App\Enum\FailureReason;
use App\Repository\ClientRepository;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RepositoryQueriesTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testFindOneForClientIsScopedToTheClient(): void
    {
        $martin = $this->client('Boulangerie Martin');
        $petitsPas = $this->client('Micro-crèche Les Petits Pas');
        $document = $this->document($martin);
        $this->em->flush();
        $this->em->clear();

        $repository = self::getContainer()->get(DocumentRepository::class);
        $martin = $this->reload($martin);
        $petitsPas = $this->reload($petitsPas);

        $this->assertSame($document->getId()->toRfc4122(), $repository->findOneForClient($document->getId(), $martin)?->getId()->toRfc4122());
        $this->assertNull($repository->findOneForClient($document->getId(), $petitsPas));
        $this->assertNull($repository->findOneForClient(Uuid::v7(), $martin));
    }

    public function testClientsAreListedWithTheirDocumentsPerStatusFailuresFirst(): void
    {
        $quiet = $this->client('Alpha Test');
        $troubled = $this->client('Zeta Test');
        $empty = $this->client('Beta Test');
        $this->document($quiet);
        $this->failedDocument($troubled);
        $this->failedDocument($troubled);
        $this->document($troubled);
        $this->em->flush();
        $this->em->clear();

        $page = self::getContainer()->get(ClientRepository::class)->searchWithStatusCounts(null, 1, 100);

        $overviews = [];
        foreach ($page->items as $overview) {
            $overviews[$overview->client->getName()] = $overview;
        }
        $this->assertSame(3, $overviews['Zeta Test']->total());
        $this->assertSame(2, $overviews['Zeta Test']->count(DocumentStatus::Failed));
        $this->assertSame(1, $overviews['Zeta Test']->count(DocumentStatus::Pending));
        $this->assertSame(0, $overviews['Zeta Test']->count(DocumentStatus::Analyzed));
        $this->assertSame(1, $overviews['Alpha Test']->total());
        $this->assertSame(0, $overviews[$empty->getName()]->total());

        $names = array_keys($overviews);
        $this->assertLessThan(array_search('Alpha Test', $names, true), array_search('Zeta Test', $names, true), 'Failures come first, whatever the name.');
        $this->assertLessThan(array_search($empty->getName(), $names, true), array_search('Alpha Test', $names, true), 'Then the name.');
    }

    public function testTheClientSearchIgnoresCaseAndAccentsAndPaginates(): void
    {
        $this->client('Micro-crèche Test');
        $this->client('Boulangerie Test');
        $this->em->flush();
        $this->em->clear();
        $repository = self::getContainer()->get(ClientRepository::class);

        $found = $repository->searchWithStatusCounts('CRECHE test', 1, 25);

        $this->assertSame(1, $found->total);
        $this->assertSame('Micro-crèche Test', $found->items[0]->client->getName());
        $this->assertSame(0, $repository->searchWithStatusCounts('introuvable', 1, 25)->total);
        $this->assertSame(0, $repository->searchWithStatusCounts('%', 1, 25)->total, 'Wildcards are searched literally.');

        $first = $repository->searchWithStatusCounts(null, 1, 1);
        $beyond = $repository->searchWithStatusCounts(null, 999, 1);
        $this->assertCount(1, $first->items);
        $this->assertSame($first->pageCount(), $beyond->number, 'An out-of-range page shows the last one.');
    }

    public function testStatusCountsAreGlobalOrPerClient(): void
    {
        $martin = $this->client('Boulangerie Martin');
        $other = $this->client('Autre Client');
        $this->failedDocument($martin);
        $this->document($martin);
        $this->document($other);
        $this->em->flush();
        $this->em->clear();
        $repository = self::getContainer()->get(DocumentRepository::class);

        $global = array_sum(array_column($repository->countByStatus(), 'count'));
        $scoped = array_column($repository->countByStatusForClient($this->reload($martin)), 'count');

        $this->assertCount(4, $repository->countByStatus(), 'Every status is present, even with no document.');
        $this->assertSame([1, 0, 0, 1], $scoped);
        $this->assertGreaterThanOrEqual(3, $global);
    }

    private function reload(Client $client): Client
    {
        $reloaded = $this->em->find(Client::class, $client->getId());
        $this->assertNotNull($reloaded);

        return $reloaded;
    }

    private function client(string $name): Client
    {
        $client = new Client($name);
        $this->em->persist($client);

        return $client;
    }

    private function document(Client $client): Document
    {
        $uploader = User::forClient($client, uniqid() . '@test.test', 'Uploader');
        $document = new Document($client, $uploader, 'scan.pdf', uniqid('key-'), 'application/pdf', 1024, str_repeat('a', 64));
        $this->em->persist($uploader);
        $this->em->persist($document);

        return $document;
    }

    private function failedDocument(Client $client): Document
    {
        $document = $this->document($client);
        $document->startAnalysis('provider-ref');
        $document->failAnalysis(FailureReason::Unknown);

        return $document;
    }
}
