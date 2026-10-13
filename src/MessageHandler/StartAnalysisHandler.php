<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Analysis\Simulation\AnalysisSimulator;
use App\Enum\DocumentStatus;
use App\Message\FinishAnalysis;
use App\Message\StartAnalysis;
use App\Repository\DocumentRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Starts the attempt and schedules its end. The simulated work is the delay of the next message,
 * not a sleep: the worker stays free for other documents.
 */
#[AsMessageHandler]
final readonly class StartAnalysisHandler
{
    public function __construct(
        private DocumentRepository $documents,
        private AnalysisSimulator $simulator,
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

        $document->startAnalysis();
        $scenario = $this->simulator->scenario($document);

        $this->bus->dispatch(
            new FinishAnalysis($message->documentId, $message->attemptId, $scenario->failure),
            [new DelayStamp($scenario->delayMs)],
        );
    }
}
