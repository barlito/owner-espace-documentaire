<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Enum\DocumentStatus;
use App\Enum\FailureReason;
use App\Repository\DocumentRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Brings back to a consistent state what messaging alone cannot guarantee: a crashed worker or a lost delayed
 * message leaves an attempt processing forever, and documents created without the queue (demo fixtures) have no
 * message at all. A sent message cannot be lost otherwise: it is written in the same transaction as the document,
 * and a message Messenger gives up on fails its attempt right away (AnalysisFailureListener). So there is no timer
 * on pending documents: one waiting in a long queue is never sent twice.
 * Idempotent and safe to run concurrently: every document is locked and re-checked before being touched.
 */
#[AsMessageHandler]
final readonly class ReconcileAnalysesHandler
{
    public function __construct(
        private DocumentRepository $documents,
        private AnalysisQueue $queue,
        private ReconcileSettings $settings,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{interrupted: int, queued: int}
     */
    public function __invoke(ReconcileAnalyses $message): array
    {
        $now = $this->clock->now();
        $interrupted = 0;
        $queued = 0;

        $stale = $this->documents->findStaleProcessing($now->modify(\sprintf('-%d seconds', $this->settings->staleAfter)), $this->settings->batch);
        foreach ($stale as ['documentId' => $documentId, 'attemptId' => $attemptId]) {
            $document = $this->documents->findForUpdate($documentId, $attemptId, DocumentStatus::Processing);
            if (null === $document) {
                continue;
            }

            $document->abandonAnalysis(FailureReason::Interrupted);
            $this->logger->warning('Analysis interrupted: no result after {seconds} s.', ['seconds' => $this->settings->staleAfter, 'document' => $documentId->toRfc4122(), 'attempt' => $attemptId->toRfc4122()]);
            ++$interrupted;
        }

        foreach ($this->documents->findNeverQueued($this->settings->batch) as ['documentId' => $documentId, 'attemptId' => $attemptId]) {
            $document = $this->documents->findForUpdate($documentId, $attemptId, DocumentStatus::Pending);
            if (null === $document || null !== $document->getCurrentAttempt()->getQueuedAt()) {
                continue;
            }

            $this->queue->enqueue($document);
            $this->logger->info('Analysis queued: its message had never been sent.', ['document' => $documentId->toRfc4122(), 'attempt' => $attemptId->toRfc4122()]);
            ++$queued;
        }

        return ['interrupted' => $interrupted, 'queued' => $queued];
    }
}
