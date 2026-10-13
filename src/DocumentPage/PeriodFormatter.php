<?php

declare(strict_types=1);

namespace App\DocumentPage;

/**
 * Period of a document in French: "Mars 2026", "2025", "Du 01/01/2025 au 30/06/2025".
 */
final class PeriodFormatter
{
    private const array MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    public function format(?\DateTimeImmutable $start, ?\DateTimeImmutable $end): ?string
    {
        if (null === $start || null === $end) {
            return null;
        }

        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return $start->format('d/m/Y');
        }

        $isWholeMonths = '01' === $start->format('d') && $end->format('d') === $end->format('t');
        if ($isWholeMonths && $start->format('Y-m') === $end->format('Y-m')) {
            return \sprintf('%s %s', self::MONTHS[(int) $start->format('n') - 1], $start->format('Y'));
        }

        if ($isWholeMonths && '01' === $start->format('m') && '12' === $end->format('m') && $start->format('Y') === $end->format('Y')) {
            return $start->format('Y');
        }

        return \sprintf('Du %s au %s', $start->format('d/m/Y'), $end->format('d/m/Y'));
    }
}
