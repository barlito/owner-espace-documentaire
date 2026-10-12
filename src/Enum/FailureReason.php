<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Known causes of a simulated analysis failure.
 */
enum FailureReason: string
{
    case UnreadableFile = 'unreadable_file';
    case UnsupportedFormat = 'unsupported_format';
    case Interrupted = 'interrupted';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::UnreadableFile => 'Le fichier est illisible',
            self::UnsupportedFormat => 'Le contenu de ce fichier ne peut pas être analysé automatiquement',
            self::Interrupted => 'Le traitement a été interrompu avant la fin',
            self::Unknown => 'La cause de l\'échec n\'est pas connue',
        };
    }
}
