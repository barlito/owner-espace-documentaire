<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\User;
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

    public function testClientsAreListedWithTheirDocumentCount(): void
    {
        $zeta = $this->client('Zeta Test');
        $alpha = $this->client('Alpha Test');
        $empty = $this->client('Beta Test');
        $this->document($alpha);
        $this->document($alpha);
        $this->document($zeta);
        $this->em->flush();
        $this->em->clear();

        $rows = self::getContainer()->get(ClientRepository::class)->findAllWithDocumentCount();
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['client']->getName()] = $row['documentCount'];
        }

        $this->assertSame(2, $counts['Alpha Test']);
        $this->assertSame(0, $counts[$empty->getName()]);
        $this->assertSame(1, $counts['Zeta Test']);

        $names = array_map(static fn (array $row): string => $row['client']->getName(), $rows);
        $sorted = $names;
        sort($sorted, \SORT_STRING | \SORT_FLAG_CASE);
        $this->assertSame($sorted, $names, 'Clients must be ordered by name.');
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
}
