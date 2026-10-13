<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dossier;

use App\Dossier\PeriodFilter;
use App\Dossier\PeriodLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PeriodFilterTest extends TestCase
{
    public function testAMonthSpansFromItsFirstToItsLastDay(): void
    {
        $period = PeriodFilter::parse('2024-02');

        $this->assertNotNull($period);
        $this->assertSame('2024-02-01', $period->start->format('Y-m-d'));
        $this->assertSame('2024-02-29', $period->end->format('Y-m-d'));
        $this->assertSame('Février 2024', $period->label());
    }

    public function testAYearSpansFromJanuaryToDecember(): void
    {
        $period = PeriodFilter::parse('2025');

        $this->assertNotNull($period);
        $this->assertSame('2025-01-01', $period->start->format('Y-m-d'));
        $this->assertSame('2025-12-31', $period->end->format('Y-m-d'));
        $this->assertSame('2025', $period->label());
    }

    #[DataProvider('invalid')]
    public function testInvalidValuesAreRejected(string $raw): void
    {
        $this->assertNull(PeriodFilter::parse($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'month 13' => ['2026-13'];
        yield 'month 00' => ['2026-00'];
        yield 'short year' => ['26'];
        yield 'too old' => ['1800'];
        yield 'full date' => ['2026-03-01'];
        yield 'text' => ['mars'];
        yield 'trailing newline' => ["2026\n"];
    }

    public function testSuggestionsListRecentYearsAndMonthsMostRecentFirst(): void
    {
        $suggestions = PeriodFilter::suggestions(new \DateTimeImmutable('2026-10-13'), null);

        $this->assertSame(['2026', '2025', '2024', '2023', '2022'], array_column(array_map(static fn (PeriodFilter $p): array => ['v' => $p->value], $suggestions['years']), 'v'));
        $this->assertCount(12, $suggestions['months']);
        $this->assertSame('2026-10', $suggestions['months'][0]->value);
        $this->assertSame('2025-11', $suggestions['months'][11]->value);
    }

    public function testAnActivePeriodOutsideTheSuggestionsIsKept(): void
    {
        $suggestions = PeriodFilter::suggestions(new \DateTimeImmutable('2026-10-13'), PeriodFilter::parse('2019-04'));

        $this->assertSame('2019-04', $suggestions['months'][12]->value);
        $this->assertCount(5, $suggestions['years']);
    }

    #[DataProvider('labels')]
    public function testLabelsAPeriodForReading(string $start, string $end, string $expected): void
    {
        $this->assertSame($expected, PeriodLabel::range(new \DateTimeImmutable($start), new \DateTimeImmutable($end)));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function labels(): iterable
    {
        yield 'month' => ['2026-03-01', '2026-03-31', 'Mars 2026'];
        yield 'december' => ['2025-12-01', '2025-12-31', 'Décembre 2025'];
        yield 'year' => ['2025-01-01', '2025-12-31', '2025'];
        yield 'quarter' => ['2026-01-01', '2026-03-31', '01/01/2026 – 31/03/2026'];
        yield 'one day' => ['2026-03-15', '2026-03-15', '15/03/2026'];
        yield 'partial month' => ['2026-03-05', '2026-03-31', '05/03/2026 – 31/03/2026'];
    }
}
