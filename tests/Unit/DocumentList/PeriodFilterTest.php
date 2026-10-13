<?php

declare(strict_types=1);

namespace App\Tests\Unit\DocumentList;

use App\DocumentList\PeriodFilter;
use App\DocumentList\PeriodLabel;
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

    public function testSuggestionsListRecentYearsAndTheAvailableMonthsGroupedByYear(): void
    {
        $suggestions = PeriodFilter::suggestions(new \DateTimeImmutable('2026-10-13'), null, ['2026-03', '2025-08', '2026-10', '2025-12']);

        $this->assertSame(['2026', '2025', '2024', '2023', '2022'], array_map(static fn (PeriodFilter $p): string => $p->value, $suggestions['years']));
        $this->assertSame(['2026', '2025'], array_column($suggestions['monthGroups'], 'year'));
        $this->assertSame(['2026-10', '2026-03'], array_map(static fn (PeriodFilter $p): string => $p->value, $suggestions['monthGroups'][0]['months']));
        $this->assertSame(['2025-12', '2025-08'], array_map(static fn (PeriodFilter $p): string => $p->value, $suggestions['monthGroups'][1]['months']));
    }

    public function testWithoutAnyDocumentNoMonthIsOffered(): void
    {
        $this->assertSame([], PeriodFilter::suggestions(new \DateTimeImmutable('2026-10-13'), null)['monthGroups']);
    }

    public function testAnActivePeriodOutsideTheSuggestionsIsKept(): void
    {
        $month = PeriodFilter::suggestions(new \DateTimeImmutable('2026-10-13'), PeriodFilter::parse('2019-04'), ['2026-10']);
        $this->assertSame(['2026', '2019'], array_column($month['monthGroups'], 'year'));
        $this->assertSame('2019-04', $month['monthGroups'][1]['months'][0]->value);

        $year = PeriodFilter::suggestions(new \DateTimeImmutable('2026-10-13'), PeriodFilter::parse('2019'));
        $this->assertCount(6, $year['years']);
        $this->assertSame('2019', $year['years'][5]->value);
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
