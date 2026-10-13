<?php

declare(strict_types=1);

namespace App\Supervision;

use App\DocumentList\SearchTerm;
use App\Enum\FailureReason;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Filters of the "À traiter" list, read from the query string like those of Mon dossier: shareable URL, working back
 * button, and an unknown or malformed value is dropped as if it had not been sent.
 */
final readonly class TodoFilters
{
    public const string PARAM_CLIENT = 'client';
    public const string PARAM_REASON = 'raison';
    public const string PARAM_SEARCH = 'q';

    public function __construct(
        public ?Uuid $clientId = null,
        public ?FailureReason $reason = null,
        public ?string $search = null,
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
        $client = self::string($params, self::PARAM_CLIENT);
        $reason = self::string($params, self::PARAM_REASON);

        return new self(
            clientId: null !== $client && Uuid::isValid($client) ? Uuid::fromString($client) : null,
            reason: null === $reason ? null : FailureReason::tryFrom($reason),
            search: SearchTerm::clean(self::string($params, self::PARAM_SEARCH)),
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
            self::PARAM_CLIENT => $this->clientId?->toRfc4122(),
            self::PARAM_REASON => $this->reason?->value,
        ], static fn (?string $value): bool => null !== $value);
    }

    /**
     * The same filters minus one parameter (a removable chip).
     */
    public function without(string $parameter): self
    {
        return new self(
            clientId: self::PARAM_CLIENT === $parameter ? null : $this->clientId,
            reason: self::PARAM_REASON === $parameter ? null : $this->reason,
            search: self::PARAM_SEARCH === $parameter ? null : $this->search,
        );
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private static function string(array $params, string $name): ?string
    {
        $value = $params[$name] ?? null;

        return \is_string($value) ? $value : null;
    }
}
