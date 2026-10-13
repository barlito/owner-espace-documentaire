<?php

declare(strict_types=1);

namespace App\Dossier;

use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use Symfony\Component\HttpFoundation\Request;

/**
 * Search and filters of "Mon dossier", read from the query string so that a URL can be shared and the back button works.
 *
 * Whatever the request holds, the result is valid: an unknown or malformed value is dropped, as if it had not
 * been sent. The client is never part of the filters; it always comes from the logged-in user.
 */
final readonly class DocumentFilters
{
    public const string PARAM_SEARCH = 'q';
    public const string PARAM_STATUS = 'statut';
    public const string PARAM_TYPE = 'nature';
    public const string PARAM_PERIOD = 'periode';

    /** Value of the "nature" parameter selecting the documents that have no nature yet. */
    public const string UNCATEGORIZED = 'aucune';

    private const int MAX_SEARCH_LENGTH = 100;

    public function __construct(
        public ?string $search = null,
        public ?DocumentStatus $status = null,
        public ?DocumentType $type = null,
        public bool $uncategorized = false,
        public ?PeriodFilter $period = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return self::fromArray($request->query->all());
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public static function fromArray(array $params): self
    {
        $nature = self::string($params, self::PARAM_TYPE);

        return new self(
            search: self::cleanSearch(self::string($params, self::PARAM_SEARCH)),
            status: null === ($status = self::string($params, self::PARAM_STATUS)) ? null : DocumentStatus::tryFrom($status),
            type: null === $nature ? null : DocumentType::tryFrom($nature),
            uncategorized: self::UNCATEGORIZED === $nature,
            period: null === ($period = self::string($params, self::PARAM_PERIOD)) ? null : PeriodFilter::parse($period),
        );
    }

    public function isActive(): bool
    {
        return [] !== $this->query();
    }

    /**
     * Query-string parameters of the active filters (no page): empty when nothing is filtered.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return array_filter([
            self::PARAM_SEARCH => $this->search,
            self::PARAM_STATUS => $this->status?->value,
            self::PARAM_TYPE => $this->uncategorized ? self::UNCATEGORIZED : $this->type?->value,
            self::PARAM_PERIOD => $this->period?->value,
        ], static fn (?string $value): bool => null !== $value);
    }

    /**
     * The same filters minus one parameter (a removable chip).
     */
    public function without(string $parameter): self
    {
        return new self(
            search: self::PARAM_SEARCH === $parameter ? null : $this->search,
            status: self::PARAM_STATUS === $parameter ? null : $this->status,
            type: self::PARAM_TYPE === $parameter ? null : $this->type,
            uncategorized: self::PARAM_TYPE === $parameter ? false : $this->uncategorized,
            period: self::PARAM_PERIOD === $parameter ? null : $this->period,
        );
    }

    /**
     * Escapes the LIKE wildcards of a search term, with the backslash as escape character.
     */
    public static function escapeLike(string $term): string
    {
        return addcslashes($term, '\\%_');
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private static function string(array $params, string $name): ?string
    {
        $value = $params[$name] ?? null;

        return \is_string($value) ? $value : null;
    }

    private static function cleanSearch(?string $raw): ?string
    {
        if (null === $raw) {
            return null;
        }

        // Invalid UTF-8 would make the database reject the query; control characters have no place in a file name.
        $term = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $raw));
        if ('' === $term) {
            return null;
        }

        return mb_substr($term, 0, self::MAX_SEARCH_LENGTH);
    }
}
