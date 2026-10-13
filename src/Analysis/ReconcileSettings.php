<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Analysis\Simulation\AnalysisSimulator;

/**
 * Reconciliation thresholds, from the environment (see .env).
 * Built when the worker boots (the schedule needs the interval), so a bad configuration stops it right away.
 */
final readonly class ReconcileSettings
{
    /**
     * @param string $interval     how often the reconciliation runs
     * @param int    $staleAfter   seconds after which a processing attempt is considered lost
     * @param int    $requeueAfter seconds after which a pending attempt is queued again
     * @param int    $batch        maximum documents handled per kind at each run
     * @param int    $maxRequeues  how many times an attempt is queued again before it is given up as failed
     */
    public function __construct(
        public string $interval,
        public int $staleAfter,
        public int $requeueAfter,
        public int $batch,
        public int $maxRequeues,
    ) {
        // A processing attempt legitimately lasts up to the longest simulated analysis: failing it earlier
        // would interrupt healthy work.
        if ($staleAfter * 1000 <= AnalysisSimulator::LONG_DELAY_MAX_MS) {
            throw new \InvalidArgumentException(\sprintf('ANALYSIS_STALE_AFTER (%d s) must exceed the longest simulated analysis (%d s).', $staleAfter, AnalysisSimulator::LONG_DELAY_MAX_MS / 1000));
        }

        if ($requeueAfter < 1 || $batch < 1 || $maxRequeues < 0) {
            throw new \InvalidArgumentException('ANALYSIS_REQUEUE_AFTER and ANALYSIS_RECONCILE_BATCH must be positive, ANALYSIS_MAX_REQUEUES cannot be negative.');
        }
    }
}
