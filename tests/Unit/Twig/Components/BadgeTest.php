<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components;

use App\Twig\Components\Badge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BadgeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function variants(): iterable
    {
        yield 'neutral' => ['neutral', 'bg-gray-50'];
        yield 'info' => ['info', 'bg-info-50'];
        yield 'success' => ['success', 'bg-success-50'];
        yield 'warning' => ['warning', 'bg-warning-100'];
        yield 'danger' => ['danger', 'bg-danger-50'];
    }

    #[DataProvider('variants')]
    public function testVariantClasses(string $variant, string $expectedClass): void
    {
        $badge = new Badge();
        $badge->variant = $variant;

        $this->assertStringContainsString($expectedClass, $badge->getClasses());
    }

    public function testUnknownVariantIsRejected(): void
    {
        $badge = new Badge();
        $badge->variant = 'fluo';

        $this->expectException(\InvalidArgumentException::class);
        $badge->getClasses();
    }
}
