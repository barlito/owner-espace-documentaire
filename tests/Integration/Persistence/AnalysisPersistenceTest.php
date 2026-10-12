<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Entity\AnalysisAttempt;
use App\Entity\Client;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentStatus;
use App\Enum\FailureReason;
use App\Tests\Integration\DatabaseConstraintAssertions;
use App\Tests\Integration\SqlState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AnalysisPersistenceTest extends KernelTestCase
{
    use DatabaseConstraintAssertions;

    private EntityManagerInterface $em;
    private User $admin;

    protected function setUp(): void
    {
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->admin = User::admin('equipe@owner.test', 'Équipe OWNER');
        $this->em->persist($this->admin);
    }

    public function testFullHistorySurvivesAReload(): void
    {
        $document = $this->failedDocument();
        $document->retryAnalysis($this->admin);
        $document->startAnalysis();
        $document->completeAnalysis(['summary' => 'Bulletin de mars 2026']);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(Document::class, $document->getId());

        $this->assertNotNull($reloaded);
        $this->assertSame(DocumentStatus::Analyzed, $reloaded->getStatus());
        $this->assertSame(2, $reloaded->getCurrentAttempt()->getNumber());
        $this->assertSame(['summary' => 'Bulletin de mars 2026'], $reloaded->getCurrentAttempt()->getResult());
        $firstAttempt = $reloaded->getAttempts()->first();
        $this->assertInstanceOf(AnalysisAttempt::class, $firstAttempt);
        $this->assertSame(FailureReason::UnreadableFile, $firstAttempt->getFailureReason());
        $this->assertSame(
            ['document_received', 'analysis_started', 'analysis_failed', 'analysis_retried', 'analysis_started', 'analysis_succeeded'],
            array_values($reloaded->getEvents()->map(static fn ($event): string => $event->getType()->value)->toArray()),
        );
        $this->assertSame('equipe@owner.test', $reloaded->getEvents()->get(3)?->getActor()?->getEmail());
    }

    public function testDatabaseRejectsAFailedAttemptCarryingAResult(): void
    {
        $document = $this->failedDocument();
        $this->em->flush();

        $this->assertConstraintViolation(SqlState::CHECK_VIOLATION, 'chk_attempt_outcome', fn () => $this->em->getConnection()->executeStatement(
            "UPDATE analysis_attempt SET result = '{\"summary\": \"fake\"}' WHERE id = ?",
            [$document->getCurrentAttempt()->getId()->toRfc4122()],
        ));
    }

    public function testDatabaseRejectsASucceededAttemptWithoutResult(): void
    {
        $document = $this->failedDocument();
        $this->em->flush();

        $this->assertConstraintViolation(SqlState::CHECK_VIOLATION, 'chk_attempt_outcome', fn () => $this->em->getConnection()->executeStatement(
            "UPDATE analysis_attempt SET status = 'succeeded', failure_reason = NULL WHERE id = ?",
            [$document->getCurrentAttempt()->getId()->toRfc4122()],
        ));
    }

    public function testDatabaseRejectsTwoUnfinishedAttemptsForTheSameDocument(): void
    {
        $document = $this->document();
        $this->em->flush();

        $this->assertConstraintViolation(SqlState::UNIQUE_VIOLATION, 'uniq_attempt_document_unfinished', fn () => $this->insertAttempt($document, 2));
    }

    public function testDatabaseRejectsADuplicateAttemptNumber(): void
    {
        $document = $this->failedDocument();
        $this->em->flush();

        $this->assertConstraintViolation(SqlState::UNIQUE_VIOLATION, 'uniq_attempt_document_number', fn () => $this->insertAttempt($document, 1));
    }

    public function testDatabaseRejectsAnUnknownEventType(): void
    {
        $document = $this->document();
        $this->em->flush();

        $this->assertConstraintViolation(SqlState::CHECK_VIOLATION, 'chk_document_event_type', fn () => $this->em->getConnection()->executeStatement(
            "UPDATE document_event SET type = 'document_deleted' WHERE document_id = ?",
            [$document->getId()->toRfc4122()],
        ));
    }

    private function insertAttempt(Document $document, int $number): void
    {
        $this->em->getConnection()->executeStatement(
            "INSERT INTO analysis_attempt (id, document_id, number, status, requested_at) VALUES (?, ?, ?, 'pending', now())",
            [Uuid::v7()->toRfc4122(), $document->getId()->toRfc4122(), $number],
        );
    }

    private function document(): Document
    {
        $client = new Client('Client A');
        $uploader = User::forClient($client, uniqid() . '@client-a.test', 'Paul');
        $document = new Document($client, $uploader, 'scan.pdf', uniqid('key-'), 'application/pdf', 1024, str_repeat('a', 64));

        $this->em->persist($client);
        $this->em->persist($uploader);
        $this->em->persist($document);

        return $document;
    }

    private function failedDocument(): Document
    {
        $document = $this->document();
        $document->startAnalysis();
        $document->failAnalysis(FailureReason::UnreadableFile);

        return $document;
    }
}
