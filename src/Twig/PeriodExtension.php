<?php

declare(strict_types=1);

namespace App\Twig;

use App\Dossier\PeriodLabel;
use Twig\Attribute\AsTwigFunction;

final class PeriodExtension
{
    /**
     * Period of a document ("Mars 2026"), or null for a document that has none.
     */
    #[AsTwigFunction('period_label')]
    public function label(?\DateTimeImmutable $start, ?\DateTimeImmutable $end): ?string
    {
        return null === $start || null === $end ? null : PeriodLabel::range($start, $end);
    }
}
