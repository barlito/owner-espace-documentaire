<?php

declare(strict_types=1);

namespace App\Analysis\Simulation;

use App\Enum\FailureReason;

/**
 * Decided up front by the simulator: how long the simulated work lasts and how it ends.
 */
final readonly class SimulatedScenario
{
    public function __construct(
        public int $delayMs,
        public ?FailureReason $failure = null,
    ) {
    }
}
