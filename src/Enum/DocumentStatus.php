<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Analysis state of a document.
 * Denormalized value: it reflects the outcome of the latest analysis attempt.
 */
enum DocumentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Analyzed = 'analyzed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente d\'analyse',
            self::Processing => 'En cours d\'analyse',
            self::Analyzed => 'Analysée',
            self::Failed => 'Échec de l\'analyse',
        };
    }

    /**
     * Short phrase read aloud by assistive technologies when the status changes live ("<file name> : <phrase>.").
     */
    public function announcement(): string
    {
        return match ($this) {
            self::Pending => 'analyse en attente',
            self::Processing => 'analyse en cours',
            self::Analyzed => 'analyse terminée',
            self::Failed => 'échec de l\'analyse',
        };
    }

    /**
     * Badge component variant.
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Pending => 'neutral',
            self::Processing => 'info',
            self::Analyzed => 'success',
            self::Failed => 'danger',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pending => 'lucide:clock',
            self::Processing => 'lucide:loader-circle',
            self::Analyzed => 'lucide:circle-check',
            self::Failed => 'lucide:circle-x',
        };
    }
}
