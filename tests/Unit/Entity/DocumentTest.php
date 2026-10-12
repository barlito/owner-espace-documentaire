<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AnalysisAttempt;
use App\Entity\Client;
use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Entity\User;
use App\Enum\AttemptStatus;
use App\Enum\DocumentEventType;
use App\Enum\DocumentStatus;
use App\Enum\FailureReason;
use App\Exception\InvalidDocumentTransition;
use PHPUnit\Framework\TestCase;

final class DocumentTest extends TestCase
{
    private Client $client;
    private User $uploader;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = new Client('Client A');
        $this->uploader = User::forClient($this->client, 'paul@client-a.test', 'Paul');
        $this->admin = User::admin('equipe@owner.test', 'Équipe OWNER');
    }

    public function testNewDocumentIsPendingWithAFirstAttemptAndAReceptionEvent(): void
    {
        $document = $this->document();

        $this->assertSame(DocumentStatus::Pending, $document->getStatus());
        $this->assertNull($document->getType());
        $this->assertSame(1, $document->getCurrentAttempt()->getNumber());
        $this->assertSame(AttemptStatus::Pending, $document->getCurrentAttempt()->getStatus());
        $this->assertNull($document->getCurrentAttempt()->getTriggeredBy());
        $this->assertSame([DocumentEventType::DocumentReceived], $this->eventTypes($document));
        $reception = $document->getEvents()->first();
        $this->assertInstanceOf(DocumentEvent::class, $reception);
        $this->assertSame($this->uploader, $reception->getActor());
    }

    public function testFailureThenRetryThenSuccessKeepsTheWholeHistory(): void
    {
        $document = $this->document();

        $document->startAnalysis();
        $document->failAnalysis(FailureReason::UnreadableFile);
        $this->assertSame(DocumentStatus::Failed, $document->getStatus());

        $retry = $document->retryAnalysis($this->admin);
        $this->assertSame(DocumentStatus::Pending, $document->getStatus());
        $this->assertSame(2, $retry->getNumber());
        $this->assertSame($this->admin, $retry->getTriggeredBy());

        $document->startAnalysis();
        $document->completeAnalysis(['summary' => 'Bulletin de mars 2026']);

        $this->assertSame(DocumentStatus::Analyzed, $document->getStatus());
        $this->assertCount(2, $document->getAttempts());

        $first = $document->getAttempts()->first();
        $this->assertInstanceOf(AnalysisAttempt::class, $first);
        $this->assertSame(AttemptStatus::Failed, $first->getStatus());
        $this->assertSame(FailureReason::UnreadableFile, $first->getFailureReason());
        $this->assertNull($first->getResult());

        $current = $document->getCurrentAttempt();
        $this->assertSame($retry, $current);
        $this->assertSame(AttemptStatus::Succeeded, $current->getStatus());
        $this->assertSame(['summary' => 'Bulletin de mars 2026'], $current->getResult());

        $this->assertSame([
            DocumentEventType::DocumentReceived,
            DocumentEventType::AnalysisStarted,
            DocumentEventType::AnalysisFailed,
            DocumentEventType::AnalysisRetried,
            DocumentEventType::AnalysisStarted,
            DocumentEventType::AnalysisSucceeded,
        ], $this->eventTypes($document));
    }

    public function testFailureEventKeepsItsCause(): void
    {
        $document = $this->document();
        $document->startAnalysis();
        $document->failAnalysis(FailureReason::Interrupted);

        $event = $document->getEvents()->last();
        $this->assertInstanceOf(DocumentEvent::class, $event);
        $this->assertSame(['reason' => 'interrupted'], $event->getPayload());
        $this->assertSame($document->getCurrentAttempt(), $event->getAttempt());
        $this->assertNull($event->getActor());
    }

    public function testRetryEventIsAttributedToTheAdmin(): void
    {
        $document = $this->failedDocument();
        $document->retryAnalysis($this->admin);

        $event = $document->getEvents()->last();
        $this->assertInstanceOf(DocumentEvent::class, $event);
        $this->assertSame(DocumentEventType::AnalysisRetried, $event->getType());
        $this->assertSame($this->admin, $event->getActor());
        $this->assertSame($this->client, $event->getClient());
    }

    public function testASecondRetryIsRejectedOnceTheFirstOneIsTakenIntoAccount(): void
    {
        $document = $this->failedDocument();
        $document->retryAnalysis($this->admin);

        $this->expectException(InvalidDocumentTransition::class);
        $document->retryAnalysis($this->admin);
    }

    public function testOnlyAFailedAnalysisCanBeRetried(): void
    {
        $this->expectException(InvalidDocumentTransition::class);
        $this->document()->retryAnalysis($this->admin);
    }

    public function testAnAnalysisCannotCompleteBeforeItStarts(): void
    {
        $this->expectException(InvalidDocumentTransition::class);
        $this->document()->completeAnalysis(['summary' => 'x']);
    }

    public function testOnlyAnAdminCanRetry(): void
    {
        $document = $this->failedDocument();

        $this->expectException(\InvalidArgumentException::class);
        $document->retryAnalysis($this->uploader);
    }

    public function testADocumentCannotBeUploadedByAnotherClientsAccount(): void
    {
        $intruder = User::forClient(new Client('Client B'), 'eve@client-b.test', 'Eve');

        $this->expectException(\InvalidArgumentException::class);
        new Document($this->client, $intruder, 'scan.pdf', 'key', 'application/pdf', 1024, str_repeat('a', 64));
    }

    public function testPeriodEndCannotPrecedeStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Document(
            $this->client,
            $this->uploader,
            'bulletin.pdf',
            'key',
            'application/pdf',
            1024,
            str_repeat('a', 64),
            periodStart: new \DateTimeImmutable('2026-03-31'),
            periodEnd: new \DateTimeImmutable('2026-03-01'),
        );
    }

    private function document(): Document
    {
        return new Document($this->client, $this->uploader, 'scan.pdf', 'key', 'application/pdf', 1024, str_repeat('a', 64));
    }

    private function failedDocument(): Document
    {
        $document = $this->document();
        $document->startAnalysis();
        $document->failAnalysis(FailureReason::Unknown);

        return $document;
    }

    /**
     * @return list<DocumentEventType>
     */
    private function eventTypes(Document $document): array
    {
        return array_values($document->getEvents()->map(static fn (DocumentEvent $event): DocumentEventType => $event->getType())->toArray());
    }
}
