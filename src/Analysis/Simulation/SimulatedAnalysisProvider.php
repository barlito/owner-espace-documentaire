<?php

declare(strict_types=1);

namespace App\Analysis\Simulation;

use App\Analysis\AnalysisOutcome;
use App\Analysis\AnalysisProvider;
use App\Analysis\AnalysisSubmission;
use App\Entity\Document;
use App\Enum\FailureReason;
use Psr\Clock\ClockInterface;

/**
 * Plays the part of a third-party provider. The scenario is drawn at submission and written into the reference
 * ("sim:<attempt>:<outcome>:<ready at, ms>"), so the provider keeps no state, like a real one that would
 * store it on its side. A long analysis is polled several times, which exercises the "still running" path.
 */
final readonly class SimulatedAnalysisProvider implements AnalysisProvider
{
    /** How often a running analysis is polled. */
    public const int POLL_INTERVAL_MS = 20_000;

    private const string SUCCESS = 'ok';

    public function __construct(
        private AnalysisSimulator $simulator,
        private ClockInterface $clock,
    ) {
    }

    public function submit(Document $document): AnalysisSubmission
    {
        $scenario = $this->simulator->scenario($document);
        $reference = \sprintf(
            'sim:%s:%s:%d',
            $document->getCurrentAttempt()->getId()->toRfc4122(),
            $scenario->failure->value ?? self::SUCCESS,
            $this->nowMs() + $scenario->delayMs,
        );

        return new AnalysisSubmission($reference, min($scenario->delayMs, self::POLL_INTERVAL_MS));
    }

    public function fetchOutcome(Document $document, string $reference): AnalysisOutcome
    {
        if (1 !== preg_match('/^sim:[0-9a-f-]{36}:(?<outcome>[a-z_]+):(?<readyAt>\d+)$/', $reference, $match)) {
            throw new \InvalidArgumentException(\sprintf('Unknown simulated analysis reference "%s".', $reference));
        }

        $remainingMs = (int) $match['readyAt'] - $this->nowMs();
        if ($remainingMs > 0) {
            return AnalysisOutcome::pending(min($remainingMs, self::POLL_INTERVAL_MS));
        }

        return self::SUCCESS === $match['outcome']
            ? AnalysisOutcome::succeeded($this->simulator->result($document))
            : AnalysisOutcome::failed(FailureReason::from($match['outcome']));
    }

    private function nowMs(): int
    {
        return (int) $this->clock->now()->format('Uv');
    }
}
