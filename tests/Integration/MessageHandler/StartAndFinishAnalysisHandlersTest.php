<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Analysis\Simulation\AnalysisSimulator;
use App\Entity\Document;
use App\Enum\AttemptStatus;
use App\Enum\DocumentEventType;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Enum\FailureReason;
use App\Message\FinishAnalysis;
use App\Message\ReconcileAnalyses;
use App\Message\StartAnalysis;
use App\MessageHandler\FinishAnalysisHandler;
use App\MessageHandler\ReconcileAnalysesHandler;
use App\MessageHandler\StartAnalysisHandler;
use App\Tests\Integration\AnalysisTestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

final class StartAndFinishAnalysisHandlersTest extends AnalysisTestCase
{
    public function testStartingMovesTheDocumentToProcessingAndSchedulesItsCompletionWithTheSimulatedDelay(): void
    {
        $document = $this->createDocument('facture-lent.pdf');

        $this->analyze($document);

        $reloaded = $this->reload($document);
        $this->assertSame(DocumentStatus::Processing, $reloaded->getStatus());
        $this->assertNotNull($reloaded->getCurrentAttempt()->getStartedAt());
        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $completion = $sent[0]->getMessage();
        $this->assertInstanceOf(FinishAnalysis::class, $completion);
        $this->assertTrue($reloaded->getCurrentAttempt()->getId()->equals($completion->attemptId));
        $this->assertNull($completion->failure);
        $delay = $sent[0]->last(DelayStamp::class)?->getDelay();
        $this->assertGreaterThanOrEqual(AnalysisSimulator::LONG_DELAY_MIN_MS, $delay);
        $this->assertLessThanOrEqual(AnalysisSimulator::LONG_DELAY_MAX_MS, $delay);
    }

    public function testADuplicateStartIsIgnored(): void
    {
        $document = $this->createDocument();

        $this->analyze($document);
        $this->analyze($document);

        $this->assertCount(1, $this->sent());
        $this->assertCount(2, $this->reload($document)->getEvents());
    }

    public function testStartsForAnotherAttemptOrAnUnknownDocumentAreIgnored(): void
    {
        $document = $this->createDocument();

        $handler = self::getContainer()->get(StartAnalysisHandler::class);
        $this->asWorker(static fn () => $handler(new StartAnalysis($document->getId(), Uuid::v7())));
        $this->asWorker(static fn () => $handler(new StartAnalysis(Uuid::v7(), Uuid::v7())));

        $this->assertSame(DocumentStatus::Pending, $this->reload($document)->getStatus());
        $this->assertSame([], $this->sent());
    }

    public function testALateStartForAnAlreadyAnalyzedDocumentIsIgnored(): void
    {
        $document = $this->createDocument();
        $this->analyze($document);
        $this->complete($document, null);

        $this->analyze($document);

        $this->assertSame(DocumentStatus::Analyzed, $this->reload($document)->getStatus());
        $this->assertCount(1, $this->sent());
    }

    public function testSuccessStoresTheResultAndFillsTheMissingTypeAndPeriod(): void
    {
        $document = $this->createDocument('Bulletin de salaire - Mars 2026.pdf');
        $this->analyze($document);

        $this->complete($document, null);

        $reloaded = $this->reload($document);
        $this->assertSame(DocumentStatus::Analyzed, $reloaded->getStatus());
        $this->assertSame(AttemptStatus::Succeeded, $reloaded->getCurrentAttempt()->getStatus());
        $result = $reloaded->getCurrentAttempt()->getResult();
        $this->assertSame('payslip', $result['detected_type'] ?? null);
        $this->assertEquals(['start' => '2026-03-01', 'end' => '2026-03-31'], $result['period'] ?? null); // jsonb reorders keys
        $this->assertSame(DocumentType::Payslip, $reloaded->getType());
        $this->assertSame('2026-03-31', $reloaded->getPeriodEnd()?->format('Y-m-d'));
        $this->assertSame(DocumentEventType::AnalysisSucceeded, $this->lastEvent($document)->getType());
    }

    public function testFailureKeepsTheReason(): void
    {
        $document = $this->createDocument();
        $this->analyze($document);

        $this->complete($document, FailureReason::UnsupportedFormat);

        $reloaded = $this->reload($document);
        $this->assertSame(DocumentStatus::Failed, $reloaded->getStatus());
        $this->assertSame(FailureReason::UnsupportedFormat, $reloaded->getCurrentAttempt()->getFailureReason());
        $this->assertNull($reloaded->getCurrentAttempt()->getResult());
    }

    public function testACompletionForADocumentThatNeverStartedIsIgnored(): void
    {
        $document = $this->createDocument();

        $this->complete($document, null);

        $this->assertSame(DocumentStatus::Pending, $this->reload($document)->getStatus());
    }

    public function testACompletionArrivingAfterTheReconciliationInterruptedTheAttemptIsIgnored(): void
    {
        $document = $this->createDocument();
        $this->analyze($document);
        $this->clock->sleep(601);
        $handler = self::getContainer()->get(ReconcileAnalysesHandler::class);
        $this->asWorker(static fn () => $handler(new ReconcileAnalyses()));

        $this->complete($document, null);

        $reloaded = $this->reload($document);
        $this->assertSame(DocumentStatus::Failed, $reloaded->getStatus());
        $this->assertSame(FailureReason::Interrupted, $reloaded->getCurrentAttempt()->getFailureReason());
        $this->assertNull($reloaded->getCurrentAttempt()->getResult());
    }

    private function analyze(Document $document): void
    {
        $handler = self::getContainer()->get(StartAnalysisHandler::class);
        $this->asWorker(static fn () => $handler(new StartAnalysis($document->getId(), $document->getCurrentAttempt()->getId())));
    }

    private function complete(Document $document, ?FailureReason $failure): void
    {
        $handler = self::getContainer()->get(FinishAnalysisHandler::class);
        $this->asWorker(static fn () => $handler(new FinishAnalysis($document->getId(), $document->getCurrentAttempt()->getId(), $failure)));
    }
}
