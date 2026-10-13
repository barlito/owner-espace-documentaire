<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Enum\FailureReason;

/**
 * Decided up front by the simulator: how long the simulated work lasts and how it ends.
 */
final readonly class AnalysisPlan
{
    public function __construct(
        public int $delayMs,
        public ?FailureReason $failure = null,
    ) {
    }
}
