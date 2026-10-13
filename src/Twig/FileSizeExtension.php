<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;

final class FileSizeExtension
{
    /**
     * Human-readable size in French units: "512 octets", "182 Ko", "3,4 Mo".
     */
    #[AsTwigFilter('file_size')]
    public function format(int $bytes): string
    {
        if ($bytes < 1024) {
            return \sprintf('%d %s', $bytes, $bytes < 2 ? 'octet' : 'octets');
        }

        if ($bytes < 1024 * 1024) {
            return \sprintf('%d Ko', (int) round($bytes / 1024));
        }

        $unit = $bytes < 1024 ** 3 ? ['Mo', 1024 ** 2] : ['Go', 1024 ** 3];

        return rtrim(rtrim(number_format($bytes / $unit[1], 1, ',', ''), '0'), ',') . ' ' . $unit[0];
    }
}
