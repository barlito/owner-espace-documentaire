<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analysis\Simulation;

use App\Analysis\Simulation\AnalysisSimulator;
use App\Entity\Client;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\FailureReason;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class AnalysisSimulatorTest extends TestCase
{
    use ClockSensitiveTrait;

    private AnalysisSimulator $simulator;

    protected function setUp(): void
    {
        self::mockTime('2026-04-15 10:00:00');
        $this->simulator = new AnalysisSimulator(new Randomizer(new Mt19937(1)));
    }

    public function testUnreadableKeywordFailsFast(): void
    {
        $scenario = $this->simulator->scenario($this->document('Relevé ILLISIBLE.pdf'));

        $this->assertSame(FailureReason::UnreadableFile, $scenario->failure);
        $this->assertFastDelay($scenario->delayMs);
    }

    public function testFailureKeywordIgnoresAccents(): void
    {
        $scenario = $this->simulator->scenario($this->document('Échec.pdf'));

        $this->assertSame(FailureReason::Unknown, $scenario->failure);
    }

    public function testSlowKeywordsSucceedAfterALongDelay(): void
    {
        foreach (['facture-lent.pdf', 'Scan LONG.pdf'] as $filename) {
            $scenario = $this->simulator->scenario($this->document($filename));

            $this->assertNull($scenario->failure);
            $this->assertGreaterThanOrEqual(AnalysisSimulator::LONG_DELAY_MIN_MS, $scenario->delayMs);
            $this->assertLessThanOrEqual(AnalysisSimulator::LONG_DELAY_MAX_MS, $scenario->delayMs);
        }
    }

    public function testWithoutKeywordTheOutcomesFollowTheDocumentedWeights(): void
    {
        $document = $this->document('scan.pdf');
        $fast = $long = $failed = 0;
        $reasons = [];

        for ($i = 0; $i < 4000; ++$i) {
            $scenario = $this->simulator->scenario($document);
            if (null !== $scenario->failure) {
                ++$failed;
                $reasons[$scenario->failure->value] = true;
            } elseif ($scenario->delayMs <= AnalysisSimulator::FAST_DELAY_MAX_MS) {
                ++$fast;
            } else {
                ++$long;
            }
        }

        $this->assertEqualsWithDelta(0.75, $fast / 4000, 0.03);
        $this->assertEqualsWithDelta(0.10, $long / 4000, 0.03);
        $this->assertEqualsWithDelta(0.15, $failed / 4000, 0.03);
        $this->assertEqualsCanonicalizing(['unreadable_file', 'unsupported_format', 'unknown'], array_keys($reasons), 'Interrupted is reserved to the reconciliation.');
    }

    public function testARetrySucceedsEvenForAFileThatFailedOnPurpose(): void
    {
        $document = $this->retriedDocument('echec.pdf', FailureReason::Unknown);

        $scenario = $this->simulator->scenario($document);

        $this->assertNull($scenario->failure);
        $this->assertFastDelay($scenario->delayMs);
    }

    public function testARetryAfterAnUnreadableFileFailsAgain(): void
    {
        $scenario = $this->simulator->scenario($this->retriedDocument('scan.pdf', FailureReason::UnreadableFile));

        $this->assertSame(FailureReason::UnreadableFile, $scenario->failure);
    }

    public function testARetryKeepsTheSlowKeyword(): void
    {
        $scenario = $this->simulator->scenario($this->retriedDocument('scan-lent.pdf', FailureReason::Interrupted));

        $this->assertNull($scenario->failure);
        $this->assertGreaterThanOrEqual(AnalysisSimulator::LONG_DELAY_MIN_MS, $scenario->delayMs);
    }

    public function testResultKeepsTheClientTypeAndHasTheDocumentedShape(): void
    {
        $result = $this->simulator->result($this->document('Bulletin de salaire - Mars 2026.pdf', DocumentType::Other));

        $json = $result->toArray();
        $this->assertSame(['detected_type', 'period', 'summary', 'fields', 'confidence'], array_keys($json));
        $this->assertSame('other', $json['detected_type']);
        $this->assertSame(['start' => '2026-03-01', 'end' => '2026-03-31'], $json['period']);
        $this->assertStringContainsString('01/03/2026', $json['summary']);
        $this->assertSame(['Émetteur', 'Date', 'Montant TTC'], array_column($json['fields'], 'label'));
        $this->assertGreaterThanOrEqual(0.8, $json['confidence']);
        $this->assertLessThanOrEqual(0.99, $json['confidence']);
        $this->assertJson(json_encode($json, \JSON_THROW_ON_ERROR));
    }

    public function testResultGuessesTheTypeAndFillsPayrollFields(): void
    {
        $json = $this->simulator->result($this->document('bulletin-2026-02.pdf'))->toArray();

        $this->assertSame('payslip', $json['detected_type']);
        $this->assertSame(['start' => '2026-02-01', 'end' => '2026-02-28'], $json['period']);
        $this->assertSame(['Salariés', 'Brut total', 'Net à payer'], array_column($json['fields'], 'label'));
        $this->assertMatchesRegularExpression('/^[\d\x{202F}]+,\d{2}\x{A0}€$/u', $json['fields'][1]['value']);
    }

    public function testResultFallsBackToOtherAndToTheMonthBeforeTheUpload(): void
    {
        $json = $this->simulator->result($this->document('IMG_0001.jpg'))->toArray();

        $this->assertSame('other', $json['detected_type']);
        $this->assertSame(['start' => '2026-03-01', 'end' => '2026-03-31'], $json['period']);
        $this->assertSame(['Émetteur', 'Date', 'Montant TTC'], array_column($json['fields'], 'label'));
    }

    public function testQuarterAndYearPeriods(): void
    {
        $quarter = $this->simulator->result($this->document('TVA-T1-2026.pdf', DocumentType::Other))->toArray();
        $year = $this->simulator->result($this->document('bilan-2024.pdf'))->toArray();
        $yearWithoutDate = $this->simulator->result($this->document('bilan.pdf'))->toArray();

        $this->assertSame(['start' => '2026-01-01', 'end' => '2026-03-31'], $quarter['period']);
        $this->assertSame('annual_accounts', $year['detected_type']);
        $this->assertSame(['start' => '2024-01-01', 'end' => '2024-12-31'], $year['period']);
        $this->assertSame(['start' => '2025-01-01', 'end' => '2025-12-31'], $yearWithoutDate['period']);
        $this->assertSame(['Exercice', 'Chiffre d\'affaires', 'Résultat net'], array_column($year['fields'], 'label'));
        $this->assertSame('2024', $year['fields'][0]['value']);
    }

    public function testDocumentsWithoutPeriodHaveNone(): void
    {
        $json = $this->simulator->result($this->document('kbis-2026-03.pdf'))->toArray();

        $this->assertSame('kbis', $json['detected_type']);
        $this->assertNull($json['period']);
        $this->assertSame(['Raison sociale', 'SIREN', 'Date'], array_column($json['fields'], 'label'));
        $this->assertSame('Boulangerie Martin', $json['fields'][0]['value']);
        $this->assertStringNotContainsString('Document reconnu : Extrait Kbis, période', $json['summary']);
    }

    public function testResultsAreReproducibleWithTheSameSeed(): void
    {
        $document = $this->document('facture.pdf');

        $this->assertSame(
            new AnalysisSimulator(new Randomizer(new Mt19937(7)))->result($document)->toArray(),
            new AnalysisSimulator(new Randomizer(new Mt19937(7)))->result($document)->toArray(),
        );
    }

    private function assertFastDelay(int $delayMs): void
    {
        $this->assertGreaterThanOrEqual(AnalysisSimulator::FAST_DELAY_MIN_MS, $delayMs);
        $this->assertLessThanOrEqual(AnalysisSimulator::FAST_DELAY_MAX_MS, $delayMs);
    }

    private function document(string $filename, ?DocumentType $type = null): Document
    {
        $client = new Client('Boulangerie Martin');

        return new Document($client, User::forClient($client, 'paul@boulangerie-martin.test', 'Paul'), $filename, 'key', 'application/pdf', 1024, str_repeat('a', 64), $type);
    }

    private function retriedDocument(string $filename, FailureReason $firstFailure): Document
    {
        $document = $this->document($filename);
        $document->startAnalysis();
        $document->failAnalysis($firstFailure);
        $document->retryAnalysis(User::admin('equipe@owner.test', 'Équipe OWNER'));

        return $document;
    }
}
