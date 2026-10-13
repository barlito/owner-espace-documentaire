<?php

declare(strict_types=1);

namespace App\DataFixtures;

/**
 * Smallest valid one-page PDF showing a few lines of text, so that fixture documents open in any viewer.
 */
final class SamplePdf
{
    /**
     * @param list<string> $lines the first line is the title
     */
    public static function render(array $lines): string
    {
        $text = "BT\n72 760 Td\n";
        foreach ($lines as $index => $line) {
            $text .= \sprintf("/F1 %d Tf\n%s(%s) Tj\n", 0 === $index ? 20 : 12, 0 === $index ? '' : "0 -24 Td\n", self::escape($line));
        }
        $text .= 'ET';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            \sprintf("<< /Length %d >>\nstream\n%s\nendstream", \strlen($text), $text),
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $object) {
            $offsets[] = \strlen($pdf);
            $pdf .= \sprintf("%d 0 obj\n%s\nendobj\n", $number + 1, $object);
        }

        // Each cross-reference entry is exactly 20 bytes, as the PDF specification requires.
        $xref = \sprintf("xref\n0 %d\n0000000000 65535 f \n", \count($objects) + 1);
        foreach ($offsets as $offset) {
            $xref .= \sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . $xref . \sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", \count($objects) + 1, \strlen($pdf));
    }

    /**
     * Standard fonts are single-byte: accents need WinAnsi (Windows-1252), and string delimiters must be escaped.
     */
    private static function escape(string $text): string
    {
        return addcslashes(mb_convert_encoding($text, 'Windows-1252', 'UTF-8'), '()\\');
    }
}
