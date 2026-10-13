<?php

declare(strict_types=1);

namespace App\DocumentList;

/**
 * Free-text search typed by a user, shared by every list that has a search field (Mon dossier, supervision, clients).
 */
final class SearchTerm
{
    public const int MAX_LENGTH = 100;

    private function __construct()
    {
    }

    /**
     * Null when nothing searchable is left. Invalid UTF-8 would make the database reject the query, and control
     * characters have no place in a name: they become spaces.
     */
    public static function clean(?string $raw): ?string
    {
        if (null === $raw) {
            return null;
        }

        $term = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $raw));
        if ('' === $term) {
            return null;
        }

        return mb_substr($term, 0, self::MAX_LENGTH);
    }

    /**
     * Escapes the LIKE wildcards of a term, with the backslash as escape character.
     */
    public static function escapeLike(string $term): string
    {
        return addcslashes($term, '\\%_');
    }
}
