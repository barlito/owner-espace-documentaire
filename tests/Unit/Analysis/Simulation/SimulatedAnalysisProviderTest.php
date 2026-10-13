<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analysis\Simulation;

use App\Analysis\Simulation\AnalysisSimulator;
use App\Analysis\Simulation\SimulatedAnalysisProvider;
use App\Entity\Client;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\FailureReason;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Clock\MockClock;

final class SimulatedAnalysisProviderTest extends TestCase
{
    private MockClock $clock;
    private SimulatedAnalysisProvider $provider;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-04-15 10:00:00');
        $this->provider = new SimulatedAnalysisProvider(new AnalysisSimulator(new Randomizer(new Mt19937(1))), $this->clock);
    }

    public function testAFastAnalysisIsCheckedOnceItIsReadyAndSucceeds(): void
    {
        $document = $this->document('Bulletin de salaire mars 2026.pdf');

        $submission = $this->provider->submit($document);

        $this->assertStringStartsWith('sim:' . $document->getCurrentAttempt()->getId()->toRfc4122() . ':ok:', $submission->reference);
        $this->assertLessThanOrEqual(AnalysisSimulator::FAST_DELAY_MAX_MS, $submission->checkAfterMs);

        $this->clock->sleep($submission->checkAfterMs / 1000);
        $outcome = $this->provider->fetchOutcome($document, $submission->reference);

        $this->assertFalse($outcome->isPending());
        $this->assertSame(DocumentType::Payslip, $outcome->result?->detectedType);
        $this->assertNull($outcome->failure);
    }

    public function testALongAnalysisIsPolledUntilItIsReady(): void
    {
        $document = $this->document('facture-lent.pdf');

        $submission = $this->provider->submit($document);
        $this->assertSame(SimulatedAnalysisProvider::POLL_INTERVAL_MS, $submission->checkAfterMs);

        $this->clock->sleep($submission->checkAfterMs / 1000);
        $checks = 1;
        while (($outcome = $this->provider->fetchOutcome($document, $submission->reference))->isPending()) {
            $this->assertLessThanOrEqual(SimulatedAnalysisProvider::POLL_INTERVAL_MS, $outcome->retryAfterMs);
            $this->clock->sleep((int) $outcome->retryAfterMs / 1000);
            ++$checks;
        }

        $this->assertGreaterThanOrEqual(3, $checks);
        $this->assertNotNull($outcome->result);
    }

    public function testASimulatedFailureIsReportedWithItsReason(): void
    {
        $document = $this->document('Kbis-illisible.pdf');

        $submission = $this->provider->submit($document);
        $this->clock->sleep($submission->checkAfterMs / 1000);

        $outcome = $this->provider->fetchOutcome($document, $submission->reference);

        $this->assertSame(FailureReason::UnreadableFile, $outcome->failure);
        $this->assertNull($outcome->result);
    }

    public function testAnUnknownReferenceIsATechnicalError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->provider->fetchOutcome($this->document('scan.pdf'), 'other-provider-42');
    }

    private function document(string $filename): Document
    {
        $client = new Client('Boulangerie Martin');

        return new Document($client, User::forClient($client, 'paul@boulangerie-martin.test', 'Paul'), $filename, 'key', 'application/pdf', 1024, str_repeat('a', 64));
    }
}
