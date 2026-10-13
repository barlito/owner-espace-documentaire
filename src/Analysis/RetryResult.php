<?php

declare(strict_types=1);

namespace App\Analysis;

final readonly class RetryResult
{
    private function __construct(
        public RetryOutcome $outcome,
        /** Number of the attempt that was created; only set when the outcome is Retried. */
        public ?int $attemptNumber = null,
    ) {
    }

    public static function retried(int $attemptNumber): self
    {
        return new self(RetryOutcome::Retried, $attemptNumber);
    }

    public static function alreadyHandled(): self
    {
        return new self(RetryOutcome::AlreadyHandled);
    }

    public static function notFound(): self
    {
        return new self(RetryOutcome::NotFound);
    }
}
