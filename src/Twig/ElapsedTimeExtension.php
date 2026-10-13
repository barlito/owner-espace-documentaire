<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\Clock\ClockInterface;
use Twig\Attribute\AsTwigFilter;

/**
 * Elapsed time in French, for the back office: "il y a 3 h" (how long ago) and "1,2 s" / "2 min 05 s" (how long it lasted).
 */
final readonly class ElapsedTimeExtension
{
    public function __construct(private ClockInterface $clock)
    {
    }

    #[AsTwigFilter('time_ago')]
    public function timeAgo(\DateTimeInterface $date): string
    {
        $seconds = $this->clock->now()->getTimestamp() - $date->getTimestamp();

        return match (true) {
            $seconds < 60 => 'à l\'instant',
            $seconds < 3600 => \sprintf('il y a %d min', intdiv($seconds, 60)),
            $seconds < 86400 => \sprintf('il y a %d h', intdiv($seconds, 3600)),
            $seconds < 86400 * 60 => \sprintf('il y a %d %s', $days = intdiv($seconds, 86400), $days > 1 ? 'jours' : 'jour'),
            default => \sprintf('il y a %d mois', intdiv($seconds, 86400 * 30)),
        };
    }

    /**
     * Null when either end is missing (an attempt that has not started or finished).
     */
    #[AsTwigFilter('duration')]
    public function duration(?\DateTimeInterface $start, ?\DateTimeInterface $end): ?string
    {
        if (null === $start || null === $end) {
            return null;
        }

        $milliseconds = max(0, (int) round(((float) $end->format('U.u') - (float) $start->format('U.u')) * 1000));

        return match (true) {
            $milliseconds < 10_000 => str_replace('.', ',', \sprintf('%.1f', $milliseconds / 1000)) . ' s',
            $milliseconds < 60_000 => \sprintf('%d s', intdiv($milliseconds, 1000)),
            default => \sprintf('%d min %02d s', intdiv($milliseconds, 60_000), intdiv($milliseconds % 60_000, 1000)),
        };
    }
}
