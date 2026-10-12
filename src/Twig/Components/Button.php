<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Button
{
    public string $variant = 'primary';
    public string $size = 'md';
    public ?string $href = null;
    public string $type = 'button';
    public ?string $icon = null;

    public function getClasses(): string
    {
        $variant = match ($this->variant) {
            'primary' => 'bg-primary-500 text-white hover:bg-primary-600',
            'secondary' => 'border border-navy-500 bg-white text-navy-500 hover:bg-navy-10',
            'ghost' => 'text-navy-500 hover:bg-navy-25',
            'danger' => 'bg-danger-600 text-white hover:bg-danger-700',
            default => throw new \InvalidArgumentException(\sprintf('Unknown button variant "%s".', $this->variant)),
        };

        $size = match ($this->size) {
            'sm' => 'px-3 py-1.5 text-sm',
            'md' => 'px-5 py-2.5 text-base',
            default => throw new \InvalidArgumentException(\sprintf('Unknown button size "%s".', $this->size)),
        };

        return 'inline-flex items-center justify-center gap-2 rounded-lg font-display font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50 ' . $variant . ' ' . $size;
    }
}
