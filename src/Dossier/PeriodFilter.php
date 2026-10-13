<?php

declare(strict_types=1);

namespace App\Dossier;

/**
 * A calendar month ("2026-03") or year ("2026") a document's period is compared with.
 */
final readonly class PeriodFilter
{
    private const int MIN_YEAR = 1900;
    private const int MAX_YEAR = 2999;

    private function __construct(
        public string $value,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }

    /**
     * Null when the input is neither "YYYY" nor "YYYY-MM" (month 01 to 12): the filter is then simply not applied.
     */
    public static function parse(string $raw): ?self
    {
        if (!preg_match('/^(?<year>[0-9]{4})(?:-(?<month>0[1-9]|1[0-2]))?$/D', $raw, $matches)) {
            return null;
        }

        $year = (int) $matches['year'];
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            return null;
        }

        $start = new \DateTimeImmutable(\sprintf('%04d-%s-01', $year, '' === ($matches['month'] ?? '') ? '01' : $matches['month']));
        $end = '' === ($matches['month'] ?? '')
            ? $start->modify('last day of december')
            : $start->modify('last day of this month');

        return new self($raw, $start, $end);
    }

    public function label(): string
    {
        return PeriodLabel::range($this->start, $this->end);
    }

    /**
     * Choices offered by the filter: the last years and months, most recent first, plus the active period when it
     * falls outside of them (a shared URL must stay faithful in the form).
     *
     * @return array{years: list<self>, months: list<self>}
     */
    public static function suggestions(\DateTimeImmutable $today, ?self $active): array
    {
        $years = [];
        for ($i = 0; $i < 5; ++$i) {
            $years[] = (string) ((int) $today->format('Y') - $i);
        }

        $months = [];
        $month = $today->modify('first day of this month');
        for ($i = 0; $i < 12; ++$i) {
            $months[] = $month->format('Y-m');
            $month = $month->modify('-1 month');
        }

        if (null !== $active) {
            if (7 === \strlen($active->value) && !\in_array($active->value, $months, true)) {
                $months[] = $active->value;
            } elseif (4 === \strlen($active->value) && !\in_array($active->value, $years, true)) {
                $years[] = $active->value;
            }
        }

        rsort($years);
        rsort($months);

        return [
            'years' => self::parseAll($years),
            'months' => self::parseAll($months),
        ];
    }

    /**
     * @param list<string> $values
     *
     * @return list<self>
     */
    private static function parseAll(array $values): array
    {
        return array_values(array_filter(array_map(self::parse(...), $values)));
    }
}
