<?php

declare(strict_types=1);

namespace App\Analysis\Provider;

use App\Analysis\AnalysisResult;
use App\Enum\FailureReason;

/**
 * Where an analysis stands on the provider side: still running, succeeded or failed.
 */
final readonly class AnalysisOutcome
{
    private function __construct(
        public ?int $retryAfterMs,
        public ?AnalysisResult $result,
        public ?FailureReason $failure,
    ) {
    }

    public static function pending(int $retryAfterMs): self
    {
        return new self(max(0, $retryAfterMs), null, null);
    }

    public static function succeeded(AnalysisResult $result): self
    {
        return new self(null, $result, null);
    }

    public static function failed(FailureReason $reason): self
    {
        return new self(null, null, $reason);
    }

    public function isPending(): bool
    {
        return null !== $this->retryAfterMs;
    }
}
