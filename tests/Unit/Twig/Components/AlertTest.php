<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components;

use App\Twig\Components\Alert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AlertTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function variants(): iterable
    {
        yield 'info' => ['info', 'bg-info-50', 'lucide:info', 'status'];
        yield 'success' => ['success', 'bg-success-50', 'lucide:circle-check', 'status'];
        yield 'warning' => ['warning', 'bg-warning-50', 'lucide:triangle-alert', 'status'];
        yield 'danger' => ['danger', 'bg-danger-50', 'lucide:circle-x', 'alert'];
    }

    #[DataProvider('variants')]
    public function testVariant(string $variant, string $expectedClass, string $expectedIcon, string $expectedRole): void
    {
        $alert = new Alert();
        $alert->variant = $variant;

        $this->assertStringContainsString($expectedClass, $alert->getClasses());
        $this->assertSame($expectedIcon, $alert->getIcon());
        $this->assertSame($expectedRole, $alert->getRole());
    }

    public function testUnknownVariantIsRejected(): void
    {
        $alert = new Alert();
        $alert->variant = 'fluo';

        $this->expectException(\InvalidArgumentException::class);
        $alert->getClasses();
    }
}
