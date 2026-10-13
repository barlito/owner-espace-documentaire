<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Analysis\AnalysisResult;
use App\Entity\AnalysisAttempt;
use App\Entity\Client;
use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Entity\User;
use App\Enum\AttemptStatus;
use App\Enum\DocumentEventType;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
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
        $result = $this->analysisResult();
        $document->completeAnalysis($result);

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
        $this->assertSame($result->toArray(), $current->getResult());

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
        $this->document()->completeAnalysis($this->analysisResult());
    }

    public function testTheAnalysisAlwaysSetsThePeriodAndFillsAMissingType(): void
    {
        $document = $this->document();
        $document->startAnalysis();
        $document->completeAnalysis($this->analysisResult());

        $this->assertSame(DocumentType::Payslip, $document->getType());
        $this->assertSame('2026-03-01', $document->getPeriodStart()?->format('Y-m-d'));
        $this->assertSame('2026-03-31', $document->getPeriodEnd()?->format('Y-m-d'));
    }

    public function testTheClientTypeWinsOverTheDetectedOneButThePeriodComesFromTheAnalysis(): void
    {
        $document = $this->document(DocumentType::Other, new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2020-01-31'));
        $document->startAnalysis();
        $document->completeAnalysis($this->analysisResult());

        $this->assertSame(DocumentType::Other, $document->getType());
        $this->assertSame('2026-03-01', $document->getPeriodStart()?->format('Y-m-d'));
        $this->assertSame('2026-03-31', $document->getPeriodEnd()?->format('Y-m-d'));
    }

    public function testAnAnalysisWithoutPeriodClearsTheDeclaredOne(): void
    {
        $document = $this->document(null, new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2020-01-31'));
        $document->startAnalysis();
        $document->completeAnalysis(new AnalysisResult(DocumentType::Kbis, null, null, 'Extrait Kbis', [], 0.9));

        $this->assertNull($document->getPeriodStart());
        $this->assertNull($document->getPeriodEnd());
    }

    public function testAResultWhosePeriodEndsBeforeItStartsIsRejectedAndChangesNothing(): void
    {
        $document = $this->document();
        $document->startAnalysis();

        try {
            $document->completeAnalysis(new AnalysisResult(null, new \DateTimeImmutable('2026-03-31'), new \DateTimeImmutable('2026-03-01'), 'x', [], 0.9));
            $this->fail('The inverted period should have been rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(DocumentStatus::Processing, $document->getStatus());
        }
    }

    public function testQueuingIsRecordedOnTheCurrentAttemptWithoutATimelineEvent(): void
    {
        $document = $this->document();
        $this->assertNull($document->getCurrentAttempt()->getQueuedAt());

        $document->markAnalysisQueued();

        $this->assertNotNull($document->getCurrentAttempt()->getQueuedAt());
        $this->assertSame([DocumentEventType::DocumentReceived], $this->eventTypes($document));
    }

    public function testQueuingIsOnlyPossibleWhilePending(): void
    {
        $document = $this->document();
        $document->startAnalysis();

        $this->expectException(InvalidDocumentTransition::class);
        $document->markAnalysisQueued();
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

    private function document(?DocumentType $type = null, ?\DateTimeImmutable $periodStart = null, ?\DateTimeImmutable $periodEnd = null): Document
    {
        return new Document($this->client, $this->uploader, 'scan.pdf', 'key', 'application/pdf', 1024, str_repeat('a', 64), $type, $periodStart, $periodEnd);
    }

    private function analysisResult(): AnalysisResult
    {
        return new AnalysisResult(
            DocumentType::Payslip,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-31'),
            'Bulletin de salaire de mars 2026',
            [['label' => 'Salariés', 'value' => '3']],
            0.93,
        );
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
