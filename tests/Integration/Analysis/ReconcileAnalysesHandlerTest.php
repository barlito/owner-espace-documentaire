<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analysis;

use App\Analysis\AnalysisQueue;
use App\Analysis\AnalyzeDocument;
use App\Analysis\AnalyzeDocumentHandler;
use App\Analysis\ReconcileAnalyses;
use App\Analysis\ReconcileAnalysesHandler;
use App\Analysis\ReconcileSettings;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentEventType;
use App\Enum\DocumentStatus;
use App\Enum\FailureReason;
use App\Repository\DocumentRepository;
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

        $this->assertSame(['interrupted' => 1, 'abandoned' => 0, 'requeued' => 0], $report);
        $staleReloaded = $this->reload($stale);
        $this->assertSame(DocumentStatus::Failed, $staleReloaded->getStatus());
        $this->assertSame(FailureReason::Interrupted, $staleReloaded->getCurrentAttempt()->getFailureReason());
        $event = $this->lastEvent($stale);
        $this->assertSame(DocumentEventType::AnalysisFailed, $event->getType());
        $this->assertSame(['reason' => 'interrupted'], $event->getPayload());
        $this->assertSame(DocumentStatus::Processing, $this->reload($fresh)->getStatus());
    }

    public function testAPendingDocumentNeverQueuedIsQueuedOnceThenLeftAloneUntilTheDelayPasses(): void
    {
        $document = $this->createDocument();

        $this->assertSame(['interrupted' => 0, 'abandoned' => 0, 'requeued' => 1], $this->reconcile());
        $this->assertSame(['interrupted' => 0, 'abandoned' => 0, 'requeued' => 0], $this->reconcile());
        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        $this->assertInstanceOf(AnalyzeDocument::class, $message);
        $this->assertTrue($document->getId()->equals($message->documentId));

        $this->clock->sleep(299);
        $this->assertSame(['interrupted' => 0, 'abandoned' => 0, 'requeued' => 0], $this->reconcile());

        $this->clock->sleep(2);
        $this->assertSame(['interrupted' => 0, 'abandoned' => 0, 'requeued' => 1], $this->reconcile());
        $this->assertCount(2, $this->sent());
        $this->assertSame(DocumentStatus::Pending, $this->reload($document)->getStatus());
    }

    public function testEachRunHandlesAtMostOneBatchPerKindOldestFirst(): void
    {
        $first = $this->createDocument();
        $this->clock->sleep(1);
        $second = $this->createDocument();
        $this->clock->sleep(1);
        $third = $this->createDocument();

        $this->assertSame(['interrupted' => 0, 'abandoned' => 0, 'requeued' => 2], $this->reconcile(batch: 2));

        $this->assertSame([$first->getId()->toRfc4122(), $second->getId()->toRfc4122()], $this->queuedDocumentIds());

        $this->assertSame(['interrupted' => 0, 'abandoned' => 0, 'requeued' => 1], $this->reconcile(batch: 2));
        $this->assertSame($third->getId()->toRfc4122(), $this->queuedDocumentIds()[2]);
    }

    public function testStaleProcessingAttemptsAreAlsoLimitedByTheBatch(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->start($this->createDocument());
            $this->clock->sleep(1);
        }
        $this->clock->sleep(700);

        $this->assertSame(['interrupted' => 2, 'abandoned' => 0, 'requeued' => 0], $this->reconcile(batch: 2));
        $this->assertSame(['interrupted' => 1, 'abandoned' => 0, 'requeued' => 0], $this->reconcile(batch: 2));
    }

    public function testAPendingDocumentIsRequeuedAtMostMaxTimesThenFailedAsUnknown(): void
    {
        $document = $this->createDocument();

        for ($round = 1; $round <= 2; ++$round) {
            $this->clock->sleep(301);
            $this->assertSame(['interrupted' => 0, 'abandoned' => 0, 'requeued' => 1], $this->reconcile(maxRequeues: 2));
            $this->assertSame($round, $this->reload($document)->getCurrentAttempt()->getRequeueCount());
        }

        $this->clock->sleep(301);
        $this->assertSame(['interrupted' => 0, 'abandoned' => 1, 'requeued' => 0], $this->reconcile(maxRequeues: 2));

        $failed = $this->reload($document);
        $this->assertSame(DocumentStatus::Failed, $failed->getStatus());
        $this->assertSame(FailureReason::Unknown, $failed->getCurrentAttempt()->getFailureReason());
        $this->assertNull($failed->getCurrentAttempt()->getStartedAt());
        $this->assertSame(DocumentEventType::AnalysisFailed, $this->lastEvent($document)->getType());
        $this->assertCount(2, $this->queuedDocumentIds());

        $this->clock->sleep(301);
        $this->assertSame(['interrupted' => 0, 'abandoned' => 0, 'requeued' => 0], $this->reconcile(maxRequeues: 2));
    }

    public function testTheInitialQueuingDoesNotCountAndANewAttemptStartsAtZero(): void
    {
        $document = $this->createDocument();
        $this->asWorker(function () use ($document): void {
            $managed = $this->em->find(Document::class, $document->getId()) ?? throw new \LogicException();
            self::getContainer()->get(AnalysisQueue::class)->enqueue($managed);
        });
        $this->assertSame(0, $this->reload($document)->getCurrentAttempt()->getRequeueCount());

        $this->clock->sleep(301);
        $this->reconcile(maxRequeues: 1);
        $this->clock->sleep(301);
        $this->reconcile(maxRequeues: 1);
        $this->assertSame(DocumentStatus::Failed, $this->reload($document)->getStatus());

        $admin = User::admin('equipe@owner.test', 'Équipe OWNER');
        $failed = $this->reload($document);
        $this->em->persist($admin);
        $failed->retryAnalysis($admin);
        $this->em->flush();

        $this->assertSame(0, $this->reload($document)->getCurrentAttempt()->getRequeueCount());
    }

    public function testSettingsRejectAStaleDelayShorterThanTheLongestSimulatedAnalysis(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReconcileSettings('1 minute', 120, 300, 100, 3);
    }

    /**
     * @return array{interrupted: int, abandoned: int, requeued: int}
     */
    private function reconcile(int $batch = 100, int $maxRequeues = 3): array
    {
        $handler = new ReconcileAnalysesHandler(
            self::getContainer()->get(DocumentRepository::class),
            self::getContainer()->get(AnalysisQueue::class),
            new ReconcileSettings('1 minute', 600, 300, $batch, $maxRequeues),
            $this->clock,
            new NullLogger(),
        );

        /** @var array{interrupted: int, abandoned: int, requeued: int} */
        return $this->asWorker(static fn (): array => $handler(new ReconcileAnalyses()));
    }

    /**
     * @return list<string>
     */
    private function queuedDocumentIds(): array
    {
        $messages = array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->sent());

        return array_values(array_map(static fn (AnalyzeDocument $message): string => $message->documentId->toRfc4122(), array_filter($messages, static fn (object $message): bool => $message instanceof AnalyzeDocument)));
    }

    private function start(Document $document): void
    {
        $handler = self::getContainer()->get(AnalyzeDocumentHandler::class);
        $this->asWorker(static fn () => $handler(new AnalyzeDocument($document->getId(), $document->getCurrentAttempt()->getId())));
    }
}
