<?php

declare(strict_types=1);

namespace App\Tests\Unit\DocumentList;

use App\DocumentList\Page;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testItDescribesAMiddlePage(): void
    {
        $page = new Page([], 123, 2, 25);

        $this->assertSame(5, $page->pageCount());
        $this->assertSame(25, $page->offset());
        $this->assertSame(['first' => 26, 'last' => 50], $page->range());
        $this->assertTrue($page->hasPrevious());
        $this->assertTrue($page->hasNext());
    }

    public function testTheLastPageIsShorter(): void
    {
        $page = new Page([], 123, 5, 25);

        $this->assertSame(['first' => 101, 'last' => 123], $page->range());
        $this->assertFalse($page->hasNext());
    }

    public function testNoResultStillHasOnePage(): void
    {
        $page = new Page([], 0, 1, 25);

        $this->assertSame(1, $page->pageCount());
        $this->assertSame(['first' => 0, 'last' => 0], $page->range());
        $this->assertFalse($page->hasPrevious());
        $this->assertFalse($page->hasNext());
    }

    /**
     * @param list<int|null> $expected
     */
    #[DataProvider('windows')]
    public function testWindowIsCompact(int $total, int $number, array $expected): void
    {
        $this->assertSame($expected, new Page([], $total, $number, 25)->window());
    }

    /**
     * @return iterable<string, array{int, int, list<int|null>}>
     */
    public static function windows(): iterable
    {
        yield 'single page' => [10, 1, [1]];
        yield 'few pages are all listed' => [100, 2, [1, 2, 3, 4]];
        yield 'start of a long list' => [1000, 1, [1, 2, null, 40]];
        yield 'a gap of one page is filled' => [1000, 3, [1, 2, 3, 4, null, 40]];
        yield 'middle' => [1000, 20, [1, null, 19, 20, 21, null, 40]];
        yield 'end of a long list' => [1000, 40, [1, null, 39, 40]];
    }

    #[DataProvider('numbers')]
    public function testParseNumberDistrustsItsInput(mixed $raw, int $expected): void
    {
        $this->assertSame($expected, Page::parseNumber($raw));
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function numbers(): iterable
    {
        yield 'valid' => ['3', 3];
        yield 'missing' => [null, 1];
        yield 'zero' => ['0', 1];
        yield 'negative' => ['-2', 1];
        yield 'text' => ['abc', 1];
        yield 'decimal' => ['2.5', 1];
        yield 'array' => [['2'], 1];
        yield 'huge' => ['99999999999999999999', 1];
    }

    public function testClampBringsAnOutOfRangePageBack(): void
    {
        $this->assertSame(5, Page::clamp(99, 123, 25));
        $this->assertSame(1, Page::clamp(3, 0, 25));
        $this->assertSame(2, Page::clamp(2, 123, 25));
    }

    public function testItRefusesAnImpossiblePage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Page([], 10, 0, 25);
    }
}
