<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components;

use App\Twig\Components\Button;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ButtonTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function variants(): iterable
    {
        yield 'primary' => ['primary', 'bg-primary-500'];
        yield 'secondary' => ['secondary', 'border-navy-500'];
        yield 'ghost' => ['ghost', 'hover:bg-navy-25'];
        yield 'danger' => ['danger', 'bg-danger-600'];
    }

    #[DataProvider('variants')]
    public function testVariantClasses(string $variant, string $expectedClass): void
    {
        $button = new Button();
        $button->variant = $variant;

        $this->assertStringContainsString($expectedClass, $button->getClasses());
    }

    public function testSizeClasses(): void
    {
        $button = new Button();
        $this->assertStringContainsString('px-5', $button->getClasses());

        $button->size = 'sm';
        $this->assertStringContainsString('px-3', $button->getClasses());
    }

    public function testUnknownVariantIsRejected(): void
    {
        $button = new Button();
        $button->variant = 'fluo';

        $this->expectException(\InvalidArgumentException::class);
        $button->getClasses();
    }
}
