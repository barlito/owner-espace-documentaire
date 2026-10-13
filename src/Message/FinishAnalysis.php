<?php

declare(strict_types=1);

namespace App\Message;

use App\Enum\FailureReason;
use Symfony\Component\Uid\Uuid;

/**
 * End of the simulated work: sent with a delay by StartAnalysisHandler, so that no worker ever sleeps.
 * The outcome was decided when the analysis started; a null reason means success.
 */
final readonly class FinishAnalysis
{
    public function __construct(
        public Uuid $documentId,
        public Uuid $attemptId,
        public ?FailureReason $failure,
    ) {
    }
}
