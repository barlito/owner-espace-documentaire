<?php

declare(strict_types=1);

namespace App\Analysis\Simulation;

use App\Enum\DocumentType;

use function Symfony\Component\String\u;

/**
 * What the simulator can read from a file name: demo keywords, document type and period.
 * Names are lowercased, stripped of accents and split on every non-alphanumeric character,
 * so "Bulletin de Salaire - Mars 2026.pdf" becomes "bulletin-de-salaire-mars-2026-pdf".
 */
final readonly class FilenameHints
{
    /** First match wins: the most specific keywords come first. */
    private const array TYPE_KEYWORDS = [
        'contrat-accueil' => DocumentType::ChildcareRegulation,
        'reglement-de-fonctionnement' => DocumentType::ChildcareRegulation,
        'bulletin' => DocumentType::Payslip,
        'salaire' => DocumentType::Payslip,
        'paie' => DocumentType::Payslip,
        'kbis' => DocumentType::Kbis,
        'statuts' => DocumentType::Articles,
        'bilan' => DocumentType::AnnualAccounts,
        'liasse' => DocumentType::AnnualAccounts,
        'dsn' => DocumentType::Dsn,
        'mutuelle' => DocumentType::HealthInsurance,
        'prevoyance' => DocumentType::HealthInsurance,
        'avis-impot' => DocumentType::TaxNotice,
        'impot' => DocumentType::TaxNotice,
        'contrat' => DocumentType::EmploymentContract,
        'avenant' => DocumentType::EmploymentContract,
    ];

    private const array MONTHS = [
        'janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6,
        'juillet' => 7, 'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12,
    ];

    private string $name;

    public function __construct(string $filename)
    {
        $this->name = u($filename)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->toString();
    }

    /**
     * Whole-word match, so that "lent" does not fire on "excellent".
     */
    public function has(string $keyword): bool
    {
        return 1 === preg_match('/(?:^|-)' . preg_quote($keyword, '/') . '(?:-|$)/', $this->name);
    }

    public function guessType(): ?DocumentType
    {
        foreach (self::TYPE_KEYWORDS as $keyword => $type) {
            if ($this->has($keyword)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * A month ("2026-03", "mars-2026"), a quarter ("t1-2026") or, when the period is a year, a year.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}|null
     */
    public function period(bool $yearly): ?array
    {
        if ($yearly) {
            return 1 === preg_match('/(?<!\d)(20\d{2})(?!\d)/', $this->name, $m) ? self::range((int) $m[1], 1, 12) : null;
        }

        if (1 === preg_match('/(?<!\d)(20\d{2})-(0[1-9]|1[0-2])(?!\d)/', $this->name, $m)) {
            return self::range((int) $m[1], (int) $m[2], (int) $m[2]);
        }

        if (1 === preg_match('/(?:^|-)(' . implode('|', array_keys(self::MONTHS)) . ')-(20\d{2})(?!\d)/', $this->name, $m)) {
            return self::range((int) $m[2], self::MONTHS[$m[1]], self::MONTHS[$m[1]]);
        }

        if (1 === preg_match('/(?:^|-)t([1-4])-(20\d{2})(?!\d)/', $this->name, $m)) {
            return self::range((int) $m[2], 3 * (int) $m[1] - 2, 3 * (int) $m[1]);
        }

        return null;
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable} first day of the first month, last day of the last one
     */
    public static function range(int $year, int $firstMonth, int $lastMonth): array
    {
        $start = new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $firstMonth));

        return [$start, new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $lastMonth))->modify('last day of this month')];
    }
}
