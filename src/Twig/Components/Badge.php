<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Badge
{
    public string $variant = 'neutral';
    public ?string $icon = null;

    public function getClasses(): string
    {
        $variant = match ($this->variant) {
            'neutral' => 'bg-gray-50 text-gray-700',
            'info' => 'bg-info-50 text-info-500',
            'success' => 'bg-success-50 text-success-700',
            'warning' => 'bg-warning-100 text-warning-800',
            'danger' => 'bg-danger-50 text-danger-700',
            default => throw new \InvalidArgumentException(\sprintf('Variante de badge inconnue : "%s".', $this->variant)),
        };

        return 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ' . $variant;
    }
}
