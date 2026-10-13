<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Entity\Document;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The only place that sends AnalyzeDocument.
 *
 * Callers run it inside a database transaction (upload, handlers through the doctrine_transaction middleware):
 * the Doctrine transport writes on the same connection, so the message and the document commit together.
 */
final readonly class AnalysisQueue
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    /**
     * @param bool $requeue true when the reconciliation sends it again: counted, to stop at some point
     */
    public function enqueue(Document $document, bool $requeue = false): void
    {
        $document->markAnalysisQueued($requeue);

        $this->bus->dispatch(new AnalyzeDocument($document->getId(), $document->getCurrentAttempt()->getId()));
    }
}
