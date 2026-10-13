<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Analysis\AnalysisProvider;
use App\Enum\DocumentStatus;
use App\Message\CheckAnalysisOutcome;
use App\Message\StartAnalysis;
use App\Repository\DocumentRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Submits the document to the provider, marks the attempt as running and schedules the first outcome check.
 */
#[AsMessageHandler]
final readonly class StartAnalysisHandler
{
    public function __construct(
        private DocumentRepository $documents,
        private AnalysisProvider $provider,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(StartAnalysis $message): void
    {
        $document = $this->documents->findForUpdate($message->documentId, $message->attemptId, DocumentStatus::Pending);
        if (null === $document) {
            // Duplicate or late message (see AnalysisQueue and the reconciliation): nothing left to do.
            $this->logger->info('Analysis request ignored: the document is gone or no longer waiting for this attempt.', [
                'document' => $message->documentId->toRfc4122(),
                'attempt' => $message->attemptId->toRfc4122(),
            ]);

            return;
        }

        // Submitted first: if the provider fails, nothing has changed and Messenger retries the message.
        $submission = $this->provider->submit($document);
        $document->startAnalysis($submission->reference);

        $this->bus->dispatch(
            new CheckAnalysisOutcome($message->documentId, $message->attemptId),
            [new DelayStamp($submission->checkAfterMs)],
        );
    }
}
