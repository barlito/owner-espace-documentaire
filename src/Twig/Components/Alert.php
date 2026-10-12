<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Alert
{
    public string $variant = 'info';
    public ?string $title = null;

    public function getClasses(): string
    {
        $variant = match ($this->variant) {
            'info' => 'border-info-100 bg-info-50 text-info-500',
            'success' => 'border-success-100 bg-success-50 text-success-700',
            'warning' => 'border-warning-100 bg-warning-50 text-warning-800',
            'danger' => 'border-danger-100 bg-danger-50 text-danger-700',
            default => throw new \InvalidArgumentException(\sprintf('Unknown alert variant "%s".', $this->variant)),
        };

        return 'flex gap-3 rounded-lg border p-4 text-sm ' . $variant;
    }

    public function getIcon(): string
    {
        return match ($this->variant) {
            'success' => 'lucide:circle-check',
            'warning' => 'lucide:triangle-alert',
            'danger' => 'lucide:circle-x',
            default => 'lucide:info',
        };
    }

    /**
     * Errors interrupt screen readers (role="alert"), other messages are announced politely.
     */
    public function getRole(): string
    {
        return 'danger' === $this->variant ? 'alert' : 'status';
    }
}
