<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Analysis\Provider\AnalysisProvider;
use App\Enum\DocumentStatus;
use App\Message\CheckAnalysisOutcome;
use App\Repository\DocumentRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Records the outcome once the provider has one, and comes back later while it is still working.
 * A provider that never answers is bounded by the reconciliation (ANALYSIS_STALE_AFTER).
 */
#[AsMessageHandler]
final readonly class CheckAnalysisOutcomeHandler
{
    public function __construct(
        private DocumentRepository $documents,
        private AnalysisProvider $provider,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CheckAnalysisOutcome $message): void
    {
        $document = $this->documents->findForUpdate($message->documentId, $message->attemptId, DocumentStatus::Processing);
        if (null === $document) {
            // For instance the reconciliation already failed this attempt as interrupted.
            $this->logger->info('Analysis outcome check ignored: the document is gone or this attempt is no longer processing.', [
                'document' => $message->documentId->toRfc4122(),
                'attempt' => $message->attemptId->toRfc4122(),
            ]);

            return;
        }

        $attempt = $document->getCurrentAttempt();
        $reference = $attempt->getProviderReference() ?? throw new \LogicException(\sprintf('Running attempt %s has no provider reference.', $attempt->getId()->toRfc4122()));
        $outcome = $this->provider->fetchOutcome($document, $reference);

        if ($outcome->isPending()) {
            $this->bus->dispatch($message, [new DelayStamp((int) $outcome->retryAfterMs)]);
        } elseif (null !== $outcome->result) {
            $document->completeAnalysis($outcome->result);
        } else {
            $document->failAnalysis($outcome->failure ?? throw new \LogicException('A finished outcome has either a result or a failure.'));
        }
    }
}
