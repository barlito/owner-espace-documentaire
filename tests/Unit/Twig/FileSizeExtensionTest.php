<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\FileSizeExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileSizeExtensionTest extends TestCase
{
    #[DataProvider('sizes')]
    public function testFormatsSizesInFrench(int $bytes, string $expected): void
    {
        $this->assertSame($expected, new FileSizeExtension()->format($bytes));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function sizes(): iterable
    {
        yield 'one byte' => [1, '1 octet'];
        yield 'bytes' => [512, '512 octets'];
        yield 'kilobytes' => [182 * 1024, '182 Ko'];
        yield 'rounded kilobytes' => [1536, '2 Ko'];
        yield 'megabytes with a decimal comma' => [(int) (3.4 * 1024 * 1024), '3,4 Mo'];
        yield 'round megabytes drop the decimal' => [20 * 1024 * 1024, '20 Mo'];
        yield 'gigabytes' => [3 * 1024 ** 3, '3 Go'];
    }
}
