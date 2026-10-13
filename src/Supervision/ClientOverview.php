<?php

declare(strict_types=1);

namespace App\Supervision;

use App\Entity\Client;
use App\Enum\DocumentStatus;

/**
 * A client and the number of its documents per status, for the clients page.
 */
final readonly class ClientOverview
{
    /**
     * @param array<string, int> $counts documents per status value; a missing status has none
     */
    public function __construct(
        public Client $client,
        private array $counts,
    ) {
    }

    public function count(DocumentStatus $status): int
    {
        return $this->counts[$status->value] ?? 0;
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }
}
