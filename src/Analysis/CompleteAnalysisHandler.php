<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Enum\DocumentStatus;
use App\Repository\DocumentRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CompleteAnalysisHandler
{
    public function __construct(
        private DocumentRepository $documents,
        private AnalysisSimulator $simulator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CompleteAnalysis $message): void
    {
        $document = $this->documents->findForUpdate($message->documentId, $message->attemptId, DocumentStatus::Processing);
        if (null === $document) {
            // For instance the reconciliation already failed this attempt as interrupted.
            $this->logger->info('Analysis completion ignored: the document is gone or this attempt is no longer processing.', [
                'document' => $message->documentId->toRfc4122(),
                'attempt' => $message->attemptId->toRfc4122(),
            ]);

            return;
        }

        if (null === $message->failure) {
            $document->completeAnalysis($this->simulator->result($document));
        } else {
            $document->failAnalysis($message->failure);
        }
    }
}
