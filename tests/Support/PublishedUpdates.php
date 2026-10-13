<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\RemoteHubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Test double of the Mercure hub: keeps what the application publishes instead of sending it over HTTP.
 * The URLs and the token factory that signs the subscription cookie come from the real configuration.
 */
final class PublishedUpdates
{
    /**
     * @var list<Update>
     */
    private array $updates = [];

    public static function hub(RemoteHubInterface $real, self $published): HubInterface
    {
        return new MockHub(
            $real->getUrl(),
            new StaticTokenProvider('test-publisher-token'),
            static function (Update $update) use ($published): string {
                $published->updates[] = $update;

                return 'urn:uuid:test-' . \count($published->updates);
            },
            $real->getFactory(),
            $real->getPublicUrl(),
            $real->getCookieName(),
            $real->getProtocolVersion(),
        );
    }

    /**
     * @return list<Update>
     */
    public function all(): array
    {
        return $this->updates;
    }
}
