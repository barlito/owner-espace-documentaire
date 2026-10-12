<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Analysis state of a document.
 * Denormalized value: it reflects the outcome of the latest analysis attempt.
 */
enum DocumentStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Analyzed = 'analyzed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Reçue',
            self::Processing => 'En cours d\'analyse',
            self::Analyzed => 'Analysée',
            self::Failed => 'Échec de l\'analyse',
        };
    }

    /**
     * Badge component variant.
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Received => 'neutral',
            self::Processing => 'info',
            self::Analyzed => 'success',
            self::Failed => 'danger',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Received => 'lucide:inbox',
            self::Processing => 'lucide:loader-circle',
            self::Analyzed => 'lucide:circle-check',
            self::Failed => 'lucide:circle-x',
        };
    }
}
