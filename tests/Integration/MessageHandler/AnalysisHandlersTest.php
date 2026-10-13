<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Analysis\AnalysisResult;
use App\Analysis\Provider\AnalysisOutcome;
use App\Entity\Document;
use App\Enum\AttemptStatus;
use App\Enum\DocumentEventType;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Enum\FailureReason;
use App\Message\CheckAnalysisOutcome;
use App\Message\ReconcileAnalyses;
use App\Message\StartAnalysis;
use App\MessageHandler\CheckAnalysisOutcomeHandler;
use App\MessageHandler\ReconcileAnalysesHandler;
use App\MessageHandler\StartAnalysisHandler;
use App\Repository\DocumentRepository;
use App\Tests\Integration\AnalysisTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

final class AnalysisHandlersTest extends AnalysisTestCase
{
    private StubAnalysisProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new StubAnalysisProvider(checkAfterMs: 7_000);
    }

    public function testStartingSubmitsTheDocumentStoresTheReferenceAndSchedulesTheFirstCheck(): void
    {
        $document = $this->createDocument();

        $this->start($document);

        $reloaded = $this->reload($document);
        $attempt = $reloaded->getCurrentAttempt();
        $this->assertSame(DocumentStatus::Processing, $reloaded->getStatus());
        $this->assertNotNull($attempt->getStartedAt());
        $this->assertSame('stub-' . $attempt->getId()->toRfc4122(), $attempt->getProviderReference());
        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $check = $sent[0]->getMessage();
        $this->assertInstanceOf(CheckAnalysisOutcome::class, $check);
        $this->assertTrue($attempt->getId()->equals($check->attemptId));
        $this->assertSame(7_000, $sent[0]->last(DelayStamp::class)?->getDelay());
    }

    public function testADuplicateStartIsIgnoredAndSubmitsOnlyOnce(): void
    {
        $document = $this->createDocument();

        $this->start($document);
        $this->start($document);

        $this->assertSame(1, $this->provider->submissions);
        $this->assertCount(1, $this->sent());
        $this->assertCount(2, $this->reload($document)->getEvents());
    }

    public function testStartsForAnotherAttemptOrAnUnknownDocumentAreIgnored(): void
    {
        $document = $this->createDocument();

        $handler = $this->startHandler();
        $this->asWorker(static fn () => $handler(new StartAnalysis($document->getId(), Uuid::v7())));
        $this->asWorker(static fn () => $handler(new StartAnalysis(Uuid::v7(), Uuid::v7())));

        $this->assertSame(DocumentStatus::Pending, $this->reload($document)->getStatus());
        $this->assertSame(0, $this->provider->submissions);
        $this->assertSame([], $this->sent());
    }

    public function testAProviderErrorAtSubmissionLeavesTheDocumentUntouched(): void
    {
        $document = $this->createDocument();
        $failing = new class implements \App\Analysis\Provider\AnalysisProvider {
            public function submit(Document $document): \App\Analysis\Provider\AnalysisSubmission
            {
                throw new \RuntimeException('Provider unavailable.');
            }

            public function fetchOutcome(Document $document, string $reference): AnalysisOutcome
            {
                throw new \LogicException('Not called.');
            }
        };
        $handler = new StartAnalysisHandler(self::getContainer()->get(DocumentRepository::class), $failing, self::getContainer()->get(MessageBusInterface::class), new NullLogger());

        try {
            $this->asWorker(static fn () => $handler(new StartAnalysis($document->getId(), $document->getCurrentAttempt()->getId())));
            $this->fail('The provider error must reach Messenger, which retries the message.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(DocumentStatus::Pending, $this->reload($document)->getStatus());
        $this->assertSame([], $this->sent());
    }

    public function testAPendingOutcomeSchedulesAnotherCheckWithTheProviderDelay(): void
    {
        $document = $this->createDocument();
        $this->start($document);
        $this->provider->willAnswer(AnalysisOutcome::pending(20_000));

        $this->check($document);

        $this->assertSame(DocumentStatus::Processing, $this->reload($document)->getStatus());
        $sent = $this->sent();
        $this->assertCount(2, $sent);
        $this->assertInstanceOf(CheckAnalysisOutcome::class, $sent[1]->getMessage());
        $this->assertSame(20_000, $sent[1]->last(DelayStamp::class)?->getDelay());
        $this->assertSame(['stub-' . $document->getCurrentAttempt()->getId()->toRfc4122()], $this->provider->fetchedReferences);
    }

    public function testSuccessStoresTheResultAndFillsTheMissingTypeAndPeriod(): void
    {
        $document = $this->createDocument();
        $this->start($document);
        $this->provider->willAnswer(AnalysisOutcome::succeeded(new AnalysisResult(
            DocumentType::Payslip,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-31'),
            'Bulletin de salaire de mars 2026.',
            [['label' => 'Salariés', 'value' => '3']],
            0.92,
        )));

        $this->check($document);

        $reloaded = $this->reload($document);
        $this->assertSame(DocumentStatus::Analyzed, $reloaded->getStatus());
        $this->assertSame(AttemptStatus::Succeeded, $reloaded->getCurrentAttempt()->getStatus());
        $result = $reloaded->getCurrentAttempt()->getResult();
        $this->assertSame('payslip', $result['detected_type'] ?? null);
        $this->assertEquals(['start' => '2026-03-01', 'end' => '2026-03-31'], $result['period'] ?? null); // jsonb reorders keys
        $this->assertSame(DocumentType::Payslip, $reloaded->getType());
        $this->assertSame('2026-03-31', $reloaded->getPeriodEnd()?->format('Y-m-d'));
        $this->assertSame(DocumentEventType::AnalysisSucceeded, $this->lastEvent($document)->getType());
        $this->assertCount(1, $this->sent());
    }

    public function testFailureKeepsTheReason(): void
    {
        $document = $this->createDocument();
        $this->start($document);
        $this->provider->willAnswer(AnalysisOutcome::failed(FailureReason::UnsupportedFormat));

        $this->check($document);

        $reloaded = $this->reload($document);
        $this->assertSame(DocumentStatus::Failed, $reloaded->getStatus());
        $this->assertSame(FailureReason::UnsupportedFormat, $reloaded->getCurrentAttempt()->getFailureReason());
        $this->assertNull($reloaded->getCurrentAttempt()->getResult());
    }

    public function testACheckForADocumentThatNeverStartedIsIgnored(): void
    {
        $document = $this->createDocument();

        $this->check($document);

        $this->assertSame(DocumentStatus::Pending, $this->reload($document)->getStatus());
        $this->assertSame([], $this->provider->fetchedReferences);
    }

    public function testACheckArrivingAfterTheReconciliationInterruptedTheAttemptIsIgnored(): void
    {
        $document = $this->createDocument();
        $this->start($document);
        $this->clock->sleep(601);
        $reconcile = self::getContainer()->get(ReconcileAnalysesHandler::class);
        $this->asWorker(static fn () => $reconcile(new ReconcileAnalyses()));

        $this->check($document);

        $reloaded = $this->reload($document);
        $this->assertSame(DocumentStatus::Failed, $reloaded->getStatus());
        $this->assertSame(FailureReason::Interrupted, $reloaded->getCurrentAttempt()->getFailureReason());
        $this->assertSame([], $this->provider->fetchedReferences);
    }

    public function testTheSimulatedProviderIsTheOneWiredByDefault(): void
    {
        $document = $this->createDocument('facture-lent.pdf');

        $handler = self::getContainer()->get(StartAnalysisHandler::class);
        $this->asWorker(static fn () => $handler(new StartAnalysis($document->getId(), $document->getCurrentAttempt()->getId())));

        $this->assertStringStartsWith('sim:', (string) $this->reload($document)->getCurrentAttempt()->getProviderReference());
    }

    private function startHandler(): StartAnalysisHandler
    {
        return new StartAnalysisHandler(self::getContainer()->get(DocumentRepository::class), $this->provider, self::getContainer()->get(MessageBusInterface::class), new NullLogger());
    }

    private function start(Document $document): void
    {
        $handler = $this->startHandler();
        $this->asWorker(static fn () => $handler(new StartAnalysis($document->getId(), $document->getCurrentAttempt()->getId())));
    }

    private function check(Document $document): void
    {
        $handler = new CheckAnalysisOutcomeHandler(self::getContainer()->get(DocumentRepository::class), $this->provider, self::getContainer()->get(MessageBusInterface::class), new NullLogger());
        $this->asWorker(static fn () => $handler(new CheckAnalysisOutcome($document->getId(), $document->getCurrentAttempt()->getId())));
    }
}
