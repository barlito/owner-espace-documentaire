<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PublishDocumentUpdate;
use App\Realtime\DocumentStreamRenderer;
use App\Realtime\Topics;
use App\Repository\DocumentRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Pushes the document's current state to the hub: once for its client's pages, once for the administrators' pages.
 * A hub failure is not caught: Messenger retries the message and eventually parks it in the failed transport. The
 * state itself is already committed, so the worst case is a page that shows it after a reload instead of live.
 * (A retry republishes both updates; they replace content, so receiving one twice changes nothing.)
 */
#[AsMessageHandler]
final readonly class PublishDocumentUpdateHandler
{
    public function __construct(
        private DocumentRepository $documents,
        private DocumentStreamRenderer $streams,
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PublishDocumentUpdate $message): void
    {
        $document = $this->documents->find($message->documentId);
        if (null === $document) {
            $this->logger->info('Live update skipped: the document is gone.', ['document' => $message->documentId->toRfc4122()]);

            return;
        }

        // Private: the hub only delivers it to subscribers whose cookie names this exact topic.
        $this->hub->publish(new Update(
            Topics::client($document->getClient()->getId()),
            $this->streams->render($document, $message->created),
            private: true,
        ));

        // A separate update, not a shared topic: the client's payload stays what it was, and the technical details
        // the back office shows (admin names, causes) never travel on a topic a client can subscribe to.
        $this->hub->publish(new Update(
            Topics::admin(),
            $this->streams->renderForAdmin($document, $message->created),
            private: true,
        ));
    }
}
