<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Asks the provider for the outcome of a running attempt. Sent with a delay (the provider's answer time), and
 * sent again with a delay while the provider is still working: no worker ever waits.
 */
final readonly class CheckAnalysisOutcome
{
    public function __construct(
        public Uuid $documentId,
        public Uuid $attemptId,
    ) {
    }
}
