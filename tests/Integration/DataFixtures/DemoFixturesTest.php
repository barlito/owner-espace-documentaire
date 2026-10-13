<?php

declare(strict_types=1);

namespace App\Tests\Integration\DataFixtures;

use App\Entity\Client;
use App\Entity\Document;
use App\Enum\DocumentEventType;
use App\Enum\DocumentStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

use function Symfony\Component\Clock\now;

final class DemoFixturesTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        // Appended inside the test transaction (DAMA rolls it back): no purge, which would truncate tables.
        /** @var list<Fixture> $fixtures */
        $fixtures = $container->get('doctrine.fixtures.loader')->getFixtures(['demo']);
        new ORMExecutor($this->em)->execute($fixtures, true);
    }

    public function testBothDemoClientsHaveEveryStatus(): void
    {
        foreach ($this->clients() as $client) {
            $statuses = array_unique(array_map(static fn (Document $d): string => $d->getStatus()->value, $this->documentsOf($client)));

            foreach (DocumentStatus::cases() as $status) {
                $this->assertContains($status->value, $statuses, \sprintf('%s has no %s document', $client->getName(), $status->value));
            }
            $this->assertGreaterThanOrEqual(25, \count($this->documentsOf($client)), $client->getName());
        }
    }

    public function testEveryDocumentHasItsStoredFile(): void
    {
        $storage = self::getContainer()->get(FilesystemOperator::class . ' $documentsStorage');
        $documents = $this->em->getRepository(Document::class)->findAll();

        $this->assertNotEmpty($documents);
        foreach ($documents as $document) {
            $content = $storage->read($document->getStorageKey());

            $this->assertSame($document->getSize(), \strlen($content), $document->getOriginalFilename());
            $this->assertSame($document->getSha256(), hash('sha256', $content), $document->getOriginalFilename());
            $this->assertSame($document->getMimeType(), new \finfo(FILEINFO_MIME_TYPE)->buffer($content), $document->getOriginalFilename());
        }
    }

    public function testDocumentsAreUploadedByAccountsOfTheirOwnClient(): void
    {
        foreach ($this->em->getRepository(Document::class)->findAll() as $document) {
            $received = $document->getEvents()->first();

            $this->assertNotFalse($received);
            $this->assertSame(DocumentEventType::DocumentReceived, $received->getType());
            $this->assertSame($document->getClient(), $received->getActor()?->getClient());
        }
    }

    public function testSomeDocumentsWereRetriedByTheAdmin(): void
    {
        $retried = array_filter(
            $this->em->getRepository(Document::class)->findAll(),
            static fn (Document $d): bool => 2 === $d->getAttempts()->count(),
        );

        $this->assertNotEmpty(array_filter($retried, static fn (Document $d): bool => DocumentStatus::Analyzed === $d->getStatus()));
        $this->assertNotEmpty(array_filter($retried, static fn (Document $d): bool => DocumentStatus::Pending === $d->getStatus()));
        foreach ($retried as $document) {
            $this->assertTrue($document->getAttempts()->get(1)?->getTriggeredBy()?->isAdmin());
        }
    }

    public function testDatesAreInThePastAndChronological(): void
    {
        $now = now();

        foreach ($this->em->getRepository(Document::class)->findAll() as $document) {
            $this->assertLessThanOrEqual($document->getUploadedAt(), $document->getClient()->getCreatedAt());

            $previous = $document->getUploadedAt();
            foreach ($document->getEvents() as $event) {
                $this->assertLessThanOrEqual($event->getOccurredAt(), $previous, $document->getOriginalFilename());
                $previous = $event->getOccurredAt();
            }
            $this->assertLessThan($now, $previous, $document->getOriginalFilename());
        }
    }

    public function testTheClockIsRestoredAfterLoading(): void
    {
        $this->assertInstanceOf(NativeClock::class, Clock::get());
    }

    /**
     * @return list<Client>
     */
    private function clients(): array
    {
        $clients = $this->em->getRepository(Client::class)->findAll();
        $this->assertCount(2, $clients);

        return $clients;
    }

    /**
     * @return list<Document>
     */
    private function documentsOf(Client $client): array
    {
        return $this->em->getRepository(Document::class)->findBy(['client' => $client]);
    }
}
