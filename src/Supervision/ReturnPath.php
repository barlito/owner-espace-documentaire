<?php

declare(strict_types=1);

namespace App\Supervision;

/**
 * The admin list a document page was opened from ("retour"), so that its back link and the redirect after a retry
 * lead back to the same filtered page. It comes from the query string: only an internal back office path is accepted,
 * anything else is ignored, which rules out an open redirect.
 */
final class ReturnPath
{
    private const int MAX_LENGTH = 500;

    private function __construct()
    {
    }

    public static function parse(mixed $raw): ?string
    {
        if (!\is_string($raw) || \strlen($raw) > self::MAX_LENGTH) {
            return null;
        }

        // "/admin" or "/admin/…" (optionally with a query string): the next character after the prefix keeps out
        // "/administrator" or "/admin@host"; no backslash, whitespace or control character can be smuggled in.
        return 1 === preg_match('~^/admin(?:[/?][^\s\\\\\x00-\x1F\x7F]*)?$~D', $raw) ? $raw : null;
    }

    /**
     * Back link wording for the page the administrator came from.
     */
    public static function label(string $path): string
    {
        return match (true) {
            str_starts_with($path, '/admin/clients/') => 'Retour au dossier du client',
            str_starts_with($path, '/admin/clients') => 'Retour aux clients',
            default => 'Retour à la supervision',
        };
    }
}
