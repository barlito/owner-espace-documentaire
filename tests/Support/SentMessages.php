<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Message\PublishDocumentUpdate;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Reads the in-memory async transport, telling the analysis messages from the live-update ones:
 * every document insertion or status change queues the latter on top of the former.
 */
final class SentMessages
{
    /**
     * @return list<Envelope> everything but the live updates
     */
    public static function analysis(InMemoryTransport $transport): array
    {
        return array_values(array_filter(
            $transport->getSent(),
            static fn (Envelope $envelope): bool => !$envelope->getMessage() instanceof PublishDocumentUpdate,
        ));
    }

    /**
     * @return list<PublishDocumentUpdate>
     */
    public static function publications(InMemoryTransport $transport): array
    {
        $messages = array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), array_values($transport->getSent()));

        return array_values(array_filter($messages, static fn (object $message): bool => $message instanceof PublishDocumentUpdate));
    }
}
