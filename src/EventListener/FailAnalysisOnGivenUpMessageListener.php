<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Enum\DocumentStatus;
use App\Enum\FailureReason;
use App\Message\FinishAnalysis;
use App\Message\StartAnalysis;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * When Messenger gives up on an analysis message (its retries are exhausted), the attempt is failed right away,
 * so that the document shows up among the failures an admin can retry, instead of waiting forever.
 * The message itself still goes to the "failed" transport for inspection; replaying it later is harmless,
 * the handlers ignore an attempt that is no longer current.
 */
#[AsEventListener]
final readonly class FailAnalysisOnGivenUpMessageListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $doctrine,
        private DocumentRepository $documents,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if ($event->willRetry() || (!$message instanceof StartAnalysis && !$message instanceof FinishAnalysis)) {
            return;
        }

        $expected = $message instanceof StartAnalysis ? DocumentStatus::Pending : DocumentStatus::Processing;
        $context = ['document' => $message->documentId->toRfc4122(), 'attempt' => $message->attemptId->toRfc4122()];

        try {
            // A failed flush closes the entity manager: start again from a fresh one.
            if (!$this->entityManager->isOpen()) {
                $this->doctrine->resetManager();
            }
            $this->entityManager->clear();

            $failed = $this->entityManager->wrapInTransaction(function () use ($message, $expected): bool {
                $document = $this->documents->findForUpdate($message->documentId, $message->attemptId, $expected);
                $document?->abandonAnalysis(FailureReason::Unknown);

                return null !== $document;
            });
        } catch (\Throwable $e) {
            // Typically the database itself is down: the document stays pending or processing (see README).
            $this->logger->error('Could not fail the analysis whose message was given up.', $context + ['exception' => $e]);

            return;
        }

        if ($failed) {
            $this->logger->warning('Analysis failed: its message kept crashing and was given up.', $context + ['exception' => $event->getThrowable()]);
        }
    }
}
