<?php

declare(strict_types=1);

namespace App\DocumentList;

/**
 * Human-readable period of a document, in French: "Mars 2026", "2025", "01/01/2026 – 31/03/2026".
 */
final class PeriodLabel
{
    private const array MONTHS = [
        1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
        'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre',
    ];

    public static function range(\DateTimeImmutable $start, \DateTimeImmutable $end): string
    {
        if ($start == $end) {
            return $start->format('d/m/Y');
        }

        if ($start->format('Y-m') === $end->format('Y-m') && '01' === $start->format('d') && $end->format('d') === $end->format('t')) {
            return self::MONTHS[(int) $start->format('n')] . ' ' . $start->format('Y');
        }

        if ($start->format('Y') === $end->format('Y') && '01-01' === $start->format('m-d') && '12-31' === $end->format('m-d')) {
            return $start->format('Y');
        }

        return $start->format('d/m/Y') . ' – ' . $end->format('d/m/Y');
    }
}
