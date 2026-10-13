<?php

declare(strict_types=1);

namespace App\Realtime;

use Symfony\Component\Uid\Uuid;

/**
 * Names of the Mercure topics, in one place: the publisher and the subscription cookie must agree on them
 * to the character, and the cookie is what keeps a client out of another client's updates.
 *
 * Path-only IRIs: the hub treats a topic as an opaque identifier, only exact equality matters.
 */
final class Topics
{
    private function __construct()
    {
    }

    /**
     * Everything that happens to one client's documents. Private: only that client's accounts may subscribe.
     */
    public static function client(Uuid $clientId): string
    {
        return \sprintf('/clients/%s/documents', $clientId->toRfc4122());
    }
}
