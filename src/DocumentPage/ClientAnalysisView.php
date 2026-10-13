<?php

declare(strict_types=1);

namespace App\DocumentPage;

use App\Enum\DocumentStatus;
use App\Enum\DocumentType;

/**
 * What the analysis block of the document page shows, for the document's current attempt.
 */
final readonly class ClientAnalysisView
{
    /**
     * @param list<array{label: string, value: string}> $fields
     */
    public function __construct(
        public DocumentStatus $status,
        /** Number of the current attempt when it is a retry (2 or more), null for the first one. */
        public ?int $retryNumber = null,
        public ?string $summary = null,
        public array $fields = [],
        public ?DocumentType $detectedType = null,
        public ?string $period = null,
        public ?string $failureMessage = null,
        public bool $suggestsNewUpload = false,
    ) {
    }
}
