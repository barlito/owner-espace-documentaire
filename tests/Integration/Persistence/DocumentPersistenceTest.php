<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Repository\DocumentRepository;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Vérifie les garanties portées par le schéma PostgreSQL (contraintes, unicité).
 * La base de test est construite par les migrations : les CHECK y sont présents.
 */
final class DocumentPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTwoFilesWithTheSameNameAreDistinctDocuments(): void
    {
        $client = new Client('Client A');
        $first = $this->document($client, 'bulletin.pdf', 'key-1', DocumentType::Payslip, '2026-01-01', '2026-01-31');
        $second = $this->document($client, 'bulletin.pdf', 'key-2', DocumentType::Payslip, '2026-02-01', '2026-02-28');
        $this->em->persist($client);
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();
        $this->em->clear();

        $documents = self::getContainer()->get(DocumentRepository::class)->findBy(['originalFilename' => 'bulletin.pdf']);

        $this->assertCount(2, $documents);
        $this->assertNotEquals($documents[0]->getId(), $documents[1]->getId());
    }

    public function testStorageKeyIsUnique(): void
    {
        $client = new Client('Client A');
        $this->em->persist($client);
        $this->em->persist($this->document($client, 'a.pdf', 'same-key'));
        $this->em->persist($this->document($client, 'b.pdf', 'same-key'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testEmailIsUnique(): void
    {
        $client = new Client('Client A');
        $this->em->persist($client);
        $this->em->persist(User::forClient($client, 'paul@example.com', 'Paul'));
        $this->em->persist(User::admin('PAUL@example.com', 'Paul admin'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testDatabaseRejectsAPeriodEndingBeforeItStarts(): void
    {
        $document = $this->persistedDocument();

        $this->assertCheckViolation('chk_document_period', static fn (EntityManagerInterface $em) => $em->getConnection()->executeStatement(
            "UPDATE document SET period_start = '2026-03-31', period_end = '2026-03-01' WHERE id = ?",
            [$document->getId()->toRfc4122()],
        ));
    }

    public function testDatabaseRejectsAnUnknownStatus(): void
    {
        $document = $this->persistedDocument();

        $this->assertCheckViolation('chk_document_status', static fn (EntityManagerInterface $em) => $em->getConnection()->executeStatement(
            "UPDATE document SET status = 'lost' WHERE id = ?",
            [$document->getId()->toRfc4122()],
        ));
    }

    /**
     * @param callable(EntityManagerInterface): mixed $statement
     */
    private function assertCheckViolation(string $constraint, callable $statement): void
    {
        try {
            $statement($this->em);
            $this->fail(\sprintf('La contrainte "%s" aurait dû être violée.', $constraint));
        } catch (DriverException $exception) {
            $this->assertSame('23514', $exception->getSQLState());
            $this->assertStringContainsString($constraint, $exception->getMessage());
        }
    }

    private function persistedDocument(): Document
    {
        $client = new Client('Client A');
        $document = $this->document($client, 'a.pdf', 'key-a');
        $this->em->persist($client);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    private function document(
        Client $client,
        string $filename,
        string $storageKey,
        ?DocumentType $type = null,
        ?string $periodStart = null,
        ?string $periodEnd = null,
    ): Document {
        return new Document(
            $client,
            $filename,
            $storageKey,
            'application/pdf',
            1024,
            str_repeat('a', 64),
            $type,
            null !== $periodStart ? new \DateTimeImmutable($periodStart) : null,
            null !== $periodEnd ? new \DateTimeImmutable($periodEnd) : null,
        );
    }
}
