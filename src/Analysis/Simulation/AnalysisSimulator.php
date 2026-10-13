<?php

declare(strict_types=1);

namespace App\Analysis\Simulation;

use App\Analysis\AnalysisResult;
use App\Entity\Document;
use App\Enum\DocumentFamily;
use App\Enum\DocumentType;
use App\Enum\FailureReason;
use Random\Randomizer;

/**
 * Stands in for a real document analysis: decides how a document analysis ends, then invents a plausible result.
 * Pure logic, the only inputs besides the document are the randomizer and the entities' clock.
 *
 * Scenarios, in order of precedence:
 *  - a retry (attempt 2 and more) succeeds, except after an unreadable file: a retry does not repair a file;
 *  - demo keywords in the file name: "illisible", "echec", "lent" or "long";
 *  - otherwise a weighted draw: 75 % fast success, 10 % long success, 15 % failure.
 */
final readonly class AnalysisSimulator
{
    public const int FAST_DELAY_MIN_MS = 3_000;
    public const int FAST_DELAY_MAX_MS = 12_000;
    public const int LONG_DELAY_MIN_MS = 60_000;
    public const int LONG_DELAY_MAX_MS = 120_000;

    private const int FAST_SUCCESS_PERCENT = 75;
    private const int LONG_SUCCESS_PERCENT = 10;

    /** Interrupted is left out: only the reconciliation produces it. */
    private const array RANDOM_FAILURES = [FailureReason::UnreadableFile, FailureReason::UnsupportedFormat, FailureReason::Unknown];

    private const array ISSUERS = ['EDF', 'Orange', 'URSSAF', 'Metro', 'Free Pro', 'Amazon Business', 'Axa'];

    public function __construct(private Randomizer $random)
    {
    }

    public function scenario(Document $document): SimulatedScenario
    {
        $filename = new ParsedFilename($document->getOriginalFilename());
        $long = $filename->has('lent') || $filename->has('long');

        if ($document->getCurrentAttempt()->getNumber() > 1) {
            $unreadable = FailureReason::UnreadableFile === $this->previousFailure($document);

            return new SimulatedScenario($this->delay($long), $unreadable ? FailureReason::UnreadableFile : null);
        }

        if ($filename->has('illisible')) {
            return new SimulatedScenario($this->delay(false), FailureReason::UnreadableFile);
        }
        if ($filename->has('echec')) {
            return new SimulatedScenario($this->delay(false), FailureReason::Unknown);
        }
        if ($long) {
            return new SimulatedScenario($this->delay(true));
        }

        $draw = $this->random->getInt(1, 100);
        if ($draw <= self::FAST_SUCCESS_PERCENT) {
            return new SimulatedScenario($this->delay(false));
        }
        if ($draw <= self::FAST_SUCCESS_PERCENT + self::LONG_SUCCESS_PERCENT) {
            return new SimulatedScenario($this->delay(true));
        }

        return new SimulatedScenario($this->delay(false), self::RANDOM_FAILURES[$this->random->getInt(0, \count(self::RANDOM_FAILURES) - 1)]);
    }

    public function result(Document $document): AnalysisResult
    {
        $filename = new ParsedFilename($document->getOriginalFilename());
        $type = $document->getType() ?? $filename->guessType() ?? DocumentType::Other;
        $period = $this->period($document, $filename, $type);

        return new AnalysisResult(
            $type,
            $period[0] ?? null,
            $period[1] ?? null,
            $this->summary($type, $period),
            $this->fields($document, $type, $period),
            $this->random->getInt(80, 99) / 100,
        );
    }

    private function delay(bool $long): int
    {
        return $long
            ? $this->random->getInt(self::LONG_DELAY_MIN_MS, self::LONG_DELAY_MAX_MS)
            : $this->random->getInt(self::FAST_DELAY_MIN_MS, self::FAST_DELAY_MAX_MS);
    }

    private function previousFailure(Document $document): ?FailureReason
    {
        $number = $document->getCurrentAttempt()->getNumber() - 1;

        return $document->getAttempts()->findFirst(static fn (int $key, $attempt): bool => $attempt->getNumber() === $number)?->getFailureReason();
    }

    /**
     * Payslips, DSN and the like cover a month, accounts and tax notices a year, the rest has no period.
     * Without a date in the file name, the period is the one that has just ended before the upload.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}|null
     */
    private function period(Document $document, ParsedFilename $filename, DocumentType $type): ?array
    {
        $yearly = match ($type) {
            DocumentType::AnnualAccounts, DocumentType::TaxNotice => true,
            DocumentType::Payslip, DocumentType::Dsn, DocumentType::InterimAccounts, DocumentType::Other => false,
            default => null,
        };
        if (null === $yearly) {
            return null;
        }

        $uploadedAt = $document->getUploadedAt();
        $previousMonth = $uploadedAt->modify('first day of last month');

        return $filename->period($yearly) ?? ($yearly
            ? ParsedFilename::range((int) $uploadedAt->format('Y') - 1, 1, 12)
            : ParsedFilename::range((int) $previousMonth->format('Y'), (int) $previousMonth->format('n'), (int) $previousMonth->format('n')));
    }

    /**
     * @param array{\DateTimeImmutable, \DateTimeImmutable}|null $period
     */
    private function summary(DocumentType $type, ?array $period): string
    {
        return null === $period
            ? \sprintf('Document reconnu : %s.', $type->label())
            : \sprintf('Document reconnu : %s, période du %s au %s.', $type->label(), $period[0]->format('d/m/Y'), $period[1]->format('d/m/Y'));
    }

    /**
     * @param array{\DateTimeImmutable, \DateTimeImmutable}|null $period
     *
     * @return list<array{label: string, value: string}>
     */
    private function fields(Document $document, DocumentType $type, ?array $period): array
    {
        $uploadedAt = $document->getUploadedAt();

        return match ($type->family()) {
            DocumentFamily::Payroll => $this->payrollFields(),
            DocumentFamily::Accounting => [
                ['label' => 'Exercice', 'value' => (string) (null === $period ? (int) $uploadedAt->format('Y') - 1 : $period[1]->format('Y'))],
                ['label' => 'Chiffre d\'affaires', 'value' => $this->euros($this->random->getInt(80_000, 900_000))],
                ['label' => 'Résultat net', 'value' => $this->euros($this->random->getInt(2_000, 90_000))],
            ],
            DocumentFamily::Legal => [
                ['label' => 'Raison sociale', 'value' => $document->getClient()->getName()],
                ['label' => 'SIREN', 'value' => \sprintf('%03d %03d %03d', $this->random->getInt(100, 999), $this->random->getInt(0, 999), $this->random->getInt(0, 999))],
                ['label' => 'Date', 'value' => $uploadedAt->modify(\sprintf('-%d days', $this->random->getInt(1, 60)))->format('d/m/Y')],
            ],
            default => [
                ['label' => 'Émetteur', 'value' => self::ISSUERS[$this->random->getInt(0, \count(self::ISSUERS) - 1)]],
                ['label' => 'Date', 'value' => $uploadedAt->modify(\sprintf('-%d days', $this->random->getInt(1, 30)))->format('d/m/Y')],
                ['label' => 'Montant TTC', 'value' => $this->euros($this->random->getInt(20, 3_000) + $this->random->getInt(0, 99) / 100)],
            ],
        };
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function payrollFields(): array
    {
        $employees = $this->random->getInt(1, 12);
        $gross = $employees * $this->random->getInt(2_200, 3_800);

        return [
            ['label' => 'Salariés', 'value' => (string) $employees],
            ['label' => 'Brut total', 'value' => $this->euros($gross)],
            ['label' => 'Net à payer', 'value' => $this->euros($gross * 0.78)],
        ];
    }

    /**
     * French format: narrow no-break space for thousands, comma for decimals, no-break space before the euro sign.
     */
    private function euros(float | int $amount): string
    {
        return number_format($amount, 2, ',', "\u{202F}") . "\u{A0}€";
    }
}
