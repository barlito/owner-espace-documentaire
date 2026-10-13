<?php

declare(strict_types=1);

namespace App\Tests\Unit\DocumentPage;

use App\DocumentPage\PeriodFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PeriodFormatterTest extends TestCase
{
    #[DataProvider('periods')]
    public function testFormatsAPeriodInFrench(?string $start, ?string $end, ?string $expected): void
    {
        $formatter = new PeriodFormatter();

        $this->assertSame($expected, $formatter->format(
            null === $start ? null : new \DateTimeImmutable($start),
            null === $end ? null : new \DateTimeImmutable($end),
        ));
    }

    /**
     * @return iterable<string, array{?string, ?string, ?string}>
     */
    public static function periods(): iterable
    {
        yield 'month' => ['2026-03-01', '2026-03-31', 'Mars 2026'];
        yield 'february of a leap year' => ['2028-02-01', '2028-02-29', 'Février 2028'];
        yield 'year' => ['2025-01-01', '2025-12-31', '2025'];
        yield 'range' => ['2025-01-01', '2025-06-30', 'Du 01/01/2025 au 30/06/2025'];
        yield 'across months' => ['2026-02-15', '2026-03-14', 'Du 15/02/2026 au 14/03/2026'];
        yield 'single day' => ['2026-03-15', '2026-03-15', '15/03/2026'];
        yield 'unknown' => [null, null, null];
    }
}
