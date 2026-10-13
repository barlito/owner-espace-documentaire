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
 * Brings back to a consistent state what messaging alone cannot guarantee: a crashed worker leaves an attempt
 * processing forever, a message lost or dead-lettered leaves a document pending forever.
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
     * @return array{interrupted: int, abandoned: int, requeued: int}
     */
    public function __invoke(ReconcileAnalyses $message): array
    {
        $now = $this->clock->now();
        $interrupted = 0;
        $requeued = 0;
        $abandoned = 0;

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

        $pending = $this->documents->findPendingToRequeue($now->modify(\sprintf('-%d seconds', $this->settings->requeueAfter)), $this->settings->batch);
        foreach ($pending as ['documentId' => $documentId, 'attemptId' => $attemptId]) {
            $document = $this->documents->findForUpdate($documentId, $attemptId, DocumentStatus::Pending);
            if (null === $document) {
                continue;
            }

            if ($document->getCurrentAttempt()->getRequeueCount() >= $this->settings->maxRequeues) {
                // The message keeps getting lost or dead-lettered: stop here, an admin can retry by hand.
                $document->abandonAnalysis(FailureReason::Unknown);
                $this->logger->warning('Analysis abandoned: still not started after {count} requeues.', ['count' => $this->settings->maxRequeues, 'document' => $documentId->toRfc4122(), 'attempt' => $attemptId->toRfc4122()]);
                ++$abandoned;

                continue;
            }

            $this->queue->enqueue($document, requeue: true);
            $this->logger->info('Analysis queued again.', ['document' => $documentId->toRfc4122(), 'attempt' => $attemptId->toRfc4122()]);
            ++$requeued;
        }

        return ['interrupted' => $interrupted, 'abandoned' => $abandoned, 'requeued' => $requeued];
    }
}
