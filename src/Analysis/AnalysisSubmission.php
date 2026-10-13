<?php

declare(strict_types=1);

namespace App\Analysis;

/**
 * What a provider answers when a document is submitted.
 */
final readonly class AnalysisSubmission
{
    /**
     * @param string $reference    identifies the analysis on the provider side; stored on the attempt
     * @param int    $checkAfterMs when to ask for the outcome the first time
     */
    public function __construct(
        public string $reference,
        public int $checkAfterMs,
    ) {
        if ('' === $reference || $checkAfterMs < 0) {
            throw new \InvalidArgumentException('A submission needs a reference and a positive delay.');
        }
    }
}
