<?php

declare(strict_types=1);

namespace App\Analysis;

/**
 * Safety net run by the scheduler: fails analyses stuck in processing and queues again those never picked up.
 */
final readonly class ReconcileAnalyses
{
}
