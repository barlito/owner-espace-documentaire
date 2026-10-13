<?php

declare(strict_types=1);

namespace App\Analysis;

/**
 * Reconciliation thresholds, from the environment (see .env).
 * Built when the worker boots (the schedule needs the interval), so a bad configuration stops it right away.
 */
final readonly class ReconciliationSettings
{
    /**
     * @param string $interval          how often the reconciliation runs
     * @param int    $staleAfter        seconds after which a processing attempt is considered lost
     * @param int    $batch             maximum documents handled per kind at each run
     * @param int    $longestAnalysisMs longest time the analysis provider may legitimately take
     */
    public function __construct(
        public string $interval,
        public int $staleAfter,
        public int $batch,
        int $longestAnalysisMs,
    ) {
        // Failing a processing attempt before the provider may answer would interrupt healthy work.
        if ($staleAfter * 1000 <= $longestAnalysisMs) {
            throw new \InvalidArgumentException(\sprintf('ANALYSIS_STALE_AFTER (%d s) must exceed the longest analysis of the provider (%d s).', $staleAfter, intdiv($longestAnalysisMs, 1000)));
        }

        if ($batch < 1) {
            throw new \InvalidArgumentException('ANALYSIS_RECONCILE_BATCH must be positive.');
        }
    }
}
