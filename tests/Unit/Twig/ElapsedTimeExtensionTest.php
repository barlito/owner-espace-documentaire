<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\ElapsedTimeExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ElapsedTimeExtensionTest extends TestCase
{
    private ElapsedTimeExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new ElapsedTimeExtension(new MockClock(new \DateTimeImmutable('2026-04-15 12:00:00')));
    }

    #[DataProvider('agos')]
    public function testHowLongAgo(string $date, string $expected): void
    {
        $this->assertSame($expected, $this->extension->timeAgo(new \DateTimeImmutable($date)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function agos(): iterable
    {
        yield 'now' => ['2026-04-15 11:59:30', 'à l\'instant'];
        yield 'minutes' => ['2026-04-15 11:15:00', 'il y a 45 min'];
        yield 'hours' => ['2026-04-15 07:00:00', 'il y a 5 h'];
        yield 'one day' => ['2026-04-14 11:00:00', 'il y a 1 jour'];
        yield 'days' => ['2026-04-10 12:00:00', 'il y a 5 jours'];
        yield 'months' => ['2025-12-15 12:00:00', 'il y a 4 mois'];
        yield 'future clock skew' => ['2026-04-15 12:00:05', 'à l\'instant'];
    }

    #[DataProvider('durations')]
    public function testHowLongItLasted(string $start, string $end, ?string $expected): void
    {
        $this->assertSame($expected, $this->extension->duration(new \DateTimeImmutable($start), new \DateTimeImmutable($end)));
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function durations(): iterable
    {
        yield 'sub-second' => ['2026-04-15 12:00:00.000', '2026-04-15 12:00:00.400', '0,4 s'];
        yield 'seconds' => ['2026-04-15 12:00:00', '2026-04-15 12:00:04.300', '4,3 s'];
        yield 'tens of seconds' => ['2026-04-15 12:00:00', '2026-04-15 12:00:32', '32 s'];
        yield 'minutes' => ['2026-04-15 12:00:00', '2026-04-15 12:02:05', '2 min 05 s'];
    }

    public function testAnUnfinishedAttemptHasNoDuration(): void
    {
        $this->assertNull($this->extension->duration(new \DateTimeImmutable(), null));
        $this->assertNull($this->extension->duration(null, new \DateTimeImmutable()));
    }
}
