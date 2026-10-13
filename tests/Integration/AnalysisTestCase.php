<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

abstract class AnalysisTestCase extends KernelTestCase
{
    use ClockSensitiveTrait;

    protected EntityManagerInterface $em;
    protected ClockInterface $clock;

    protected function setUp(): void
    {
        $this->clock = self::mockTime('2026-04-15 10:00:00');
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createDocument(string $filename = 'scan.pdf'): Document
    {
        $suffix = bin2hex(random_bytes(4));
        $client = new Client('Boulangerie Martin');
        $uploader = User::forClient($client, "paul-$suffix@boulangerie-martin.test", 'Paul Martin');
        $document = new Document($client, $uploader, $filename, "key-$suffix", 'application/pdf', 1024, str_repeat('a', 64));
        $this->em->persist($client);
        $this->em->persist($uploader);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    /**
     * Runs like a worker does: on a fresh entity manager, in the transaction (and flush) of the doctrine_transaction middleware.
     * DAMA's own transaction is invisible to Doctrine, which is why the pessimistic lock needs this one.
     */
    protected function asWorker(callable $work): mixed
    {
        $this->em->clear();
        $result = $this->em->wrapInTransaction($work);
        $this->em->clear();

        return $result;
    }

    /**
     * Reads the document back from the database.
     */
    protected function reload(Document $document): Document
    {
        $this->em->clear();

        return $this->em->find(Document::class, $document->getId()) ?? throw new \LogicException('Document not found.');
    }

    protected function lastEvent(Document $document): DocumentEvent
    {
        return $this->reload($document)->getEvents()->last() ?: throw new \LogicException('The document has no event.');
    }

    /**
     * @return list<Envelope>
     */
    protected function sent(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return array_values($transport->getSent());
    }
}
