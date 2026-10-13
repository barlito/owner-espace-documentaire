<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Analysis\AnalysisQueue;
use App\Analysis\ReconciliationSettings;
use App\Entity\Document;
use App\Enum\DocumentEventType;
use App\Enum\DocumentStatus;
use App\Enum\FailureReason;
use App\Message\ReconcileAnalyses;
use App\Message\StartAnalysis;
use App\MessageHandler\ReconcileAnalysesHandler;
use App\MessageHandler\StartAnalysisHandler;
use App\Repository\DocumentRepository;
use App\Tests\Integration\AnalysisTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;

final class ReconcileAnalysesHandlerTest extends AnalysisTestCase
{
    public function testAStaleProcessingAttemptIsFailedAsInterruptedWithATimelineEvent(): void
    {
        $stale = $this->createDocument();
        $this->start($stale);
        $this->clock->sleep(500);
        $fresh = $this->createDocument();
        $this->start($fresh);
        $this->clock->sleep(101);

        $report = $this->reconcile();

        $this->assertSame(['interrupted' => 1, 'queued' => 0], $report);
        $staleReloaded = $this->reload($stale);
        $this->assertSame(DocumentStatus::Failed, $staleReloaded->getStatus());
        $this->assertSame(FailureReason::Interrupted, $staleReloaded->getCurrentAttempt()->getFailureReason());
        $event = $this->lastEvent($stale);
        $this->assertSame(DocumentEventType::AnalysisFailed, $event->getType());
        $this->assertSame(['reason' => 'interrupted'], $event->getPayload());
        $this->assertSame(DocumentStatus::Processing, $this->reload($fresh)->getStatus());
    }

    public function testAPendingDocumentNeverQueuedIsQueuedOnce(): void
    {
        $document = $this->createDocument();

        $this->assertSame(['interrupted' => 0, 'queued' => 1], $this->reconcile());
        $this->assertSame(['interrupted' => 0, 'queued' => 0], $this->reconcile());
        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        $this->assertInstanceOf(StartAnalysis::class, $message);
        $this->assertTrue($document->getId()->equals($message->documentId));
        $this->assertSame(DocumentStatus::Pending, $this->reload($document)->getStatus());
    }

    public function testAQueuedDocumentWaitingItsTurnIsNeverSentAgain(): void
    {
        $document = $this->createDocument();
        $this->enqueue($document);

        $this->clock->sleep(86_400);

        $this->assertSame(['interrupted' => 0, 'queued' => 0], $this->reconcile());
        $this->assertCount(1, $this->sent());
        $this->assertSame(DocumentStatus::Pending, $this->reload($document)->getStatus());
    }

    public function testEachRunHandlesAtMostOneBatchPerKindOldestFirst(): void
    {
        $first = $this->createDocument();
        $this->clock->sleep(1);
        $second = $this->createDocument();
        $this->clock->sleep(1);
        $third = $this->createDocument();

        $this->assertSame(['interrupted' => 0, 'queued' => 2], $this->reconcile(batch: 2));

        $this->assertSame([$first->getId()->toRfc4122(), $second->getId()->toRfc4122()], $this->queuedDocumentIds());

        $this->assertSame(['interrupted' => 0, 'queued' => 1], $this->reconcile(batch: 2));
        $this->assertSame($third->getId()->toRfc4122(), $this->queuedDocumentIds()[2]);
    }

    public function testStaleProcessingAttemptsAreAlsoLimitedByTheBatch(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->start($this->createDocument());
            $this->clock->sleep(1);
        }
        $this->clock->sleep(700);

        $this->assertSame(['interrupted' => 2, 'queued' => 0], $this->reconcile(batch: 2));
        $this->assertSame(['interrupted' => 1, 'queued' => 0], $this->reconcile(batch: 2));
    }

    public function testSettingsRejectAStaleDelayShorterThanTheLongestSimulatedAnalysis(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReconciliationSettings('1 minute', 120, 100, 120_000);
    }

    /**
     * @return array{interrupted: int, queued: int}
     */
    private function reconcile(int $batch = 100): array
    {
        $handler = new ReconcileAnalysesHandler(
            self::getContainer()->get(DocumentRepository::class),
            self::getContainer()->get(AnalysisQueue::class),
            new ReconciliationSettings('1 minute', 600, $batch, 120_000),
            $this->clock,
            new NullLogger(),
        );

        /** @var array{interrupted: int, queued: int} */
        return $this->asWorker(static fn (): array => $handler(new ReconcileAnalyses()));
    }

    /**
     * @return list<string>
     */
    private function queuedDocumentIds(): array
    {
        $messages = array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->sent());

        return array_values(array_map(static fn (StartAnalysis $message): string => $message->documentId->toRfc4122(), array_filter($messages, static fn (object $message): bool => $message instanceof StartAnalysis)));
    }

    private function enqueue(Document $document): void
    {
        $queue = self::getContainer()->get(AnalysisQueue::class);
        $this->asWorker(function () use ($document, $queue): void {
            $queue->enqueue($this->em->find(Document::class, $document->getId()) ?? throw new \LogicException('Document not found.'));
        });
    }

    private function start(Document $document): void
    {
        $handler = self::getContainer()->get(StartAnalysisHandler::class);
        $this->asWorker(static fn () => $handler(new StartAnalysis($document->getId(), $document->getCurrentAttempt()->getId())));
    }
}
