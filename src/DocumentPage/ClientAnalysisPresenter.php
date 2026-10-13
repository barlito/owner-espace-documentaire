<?php

declare(strict_types=1);

namespace App\DocumentPage;

use App\Entity\Document;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Enum\FailureReason;

/**
 * Reads the current attempt of a document into what the client page shows (result of a success, wording of a failure).
 */
final readonly class ClientAnalysisPresenter
{
    public function __construct(
        private PeriodFormatter $periodFormatter = new PeriodFormatter(),
        private ClientFailureWording $failureWording = new ClientFailureWording(),
    ) {
    }

    public function present(Document $document): ClientAnalysisView
    {
        $attempt = $document->getCurrentAttempt();
        $retryNumber = $attempt->getNumber() > 1 ? $attempt->getNumber() : null;

        if (DocumentStatus::Analyzed === $document->getStatus()) {
            return $this->analyzed($document, $retryNumber);
        }

        if (DocumentStatus::Failed === $document->getStatus()) {
            $reason = $attempt->getFailureReason() ?? FailureReason::Unknown;

            return new ClientAnalysisView(
                DocumentStatus::Failed,
                $retryNumber,
                failureMessage: $this->failureWording->explanation($reason),
                suggestsNewUpload: $this->failureWording->suggestsNewUpload($reason),
            );
        }

        return new ClientAnalysisView($document->getStatus(), $retryNumber);
    }

    private function analyzed(Document $document, ?int $retryNumber): ClientAnalysisView
    {
        $result = $document->getCurrentAttempt()->getResult() ?? [];

        $fields = [];
        foreach (\is_array($result['fields'] ?? null) ? $result['fields'] : [] as $field) {
            if (\is_array($field) && \is_string($field['label'] ?? null) && \is_string($field['value'] ?? null)) {
                $fields[] = ['label' => $field['label'], 'value' => $field['value']];
            }
        }

        $detectedType = \is_string($result['detected_type'] ?? null) ? DocumentType::tryFrom($result['detected_type']) : null;

        return new ClientAnalysisView(
            DocumentStatus::Analyzed,
            $retryNumber,
            summary: \is_string($result['summary'] ?? null) ? $result['summary'] : null,
            fields: $fields,
            detectedType: $detectedType,
            period: $this->periodFormatter->format($document->getPeriodStart(), $document->getPeriodEnd()),
        );
    }
}
