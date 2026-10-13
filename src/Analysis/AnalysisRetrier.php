<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Entity\User;
use App\Enum\DocumentStatus;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Retries the analysis of a failed document, safely when several requests ask for it at once (double click,
 * two administrators on the same document).
 *
 * Everything happens in one transaction: the document row is locked, its state is checked under the lock, then the
 * new attempt is created and its message written. The second request waits for the lock, finds the document
 * pending and does nothing, so one click or ten produce exactly one attempt and one message. The database backs
 * this up: a document can never have two unfinished attempts (see AnalysisAttempt).
 */
final readonly class AnalysisRetrier
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentRepository $documents,
        private AnalysisQueue $queue,
    ) {
    }

    public function retry(Uuid $documentId, User $admin): RetryResult
    {
        return $this->entityManager->wrapInTransaction(function () use ($documentId, $admin): RetryResult {
            $document = $this->documents->findLocked($documentId);
            if (null === $document) {
                return RetryResult::notFound();
            }

            if (DocumentStatus::Failed !== $document->getStatus()) {
                return RetryResult::alreadyHandled();
            }

            $attempt = $document->retryAnalysis($admin);
            $this->queue->enqueue($document);

            return RetryResult::retried($attempt->getNumber());
        });
    }
}
