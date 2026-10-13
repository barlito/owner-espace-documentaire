<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Analysis\AnalysisOutcome;
use App\Analysis\AnalysisProvider;
use App\Analysis\AnalysisSubmission;
use App\Entity\Document;

/**
 * Provider whose answers are set by the test: the handlers are tested apart from the simulation.
 */
final class StubAnalysisProvider implements AnalysisProvider
{
    /** @var list<AnalysisOutcome> */
    private array $outcomes = [];

    /** @var list<string> */
    public array $fetchedReferences = [];

    public int $submissions = 0;

    public function __construct(private readonly int $checkAfterMs = 5_000)
    {
    }

    public function willAnswer(AnalysisOutcome ...$outcomes): void
    {
        $this->outcomes = array_values($outcomes);
    }

    public function submit(Document $document): AnalysisSubmission
    {
        ++$this->submissions;

        return new AnalysisSubmission('stub-' . $document->getCurrentAttempt()->getId()->toRfc4122(), $this->checkAfterMs);
    }

    public function fetchOutcome(Document $document, string $reference): AnalysisOutcome
    {
        $this->fetchedReferences[] = $reference;

        return array_shift($this->outcomes) ?? throw new \LogicException('No outcome left: call willAnswer() first.');
    }
}
