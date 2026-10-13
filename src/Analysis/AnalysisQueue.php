<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Entity\Document;
use App\Message\StartAnalysis;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The only place that sends StartAnalysis.
 *
 * Callers run it inside a database transaction (upload, handlers through the doctrine_transaction middleware):
 * the Doctrine transport writes on the same connection, so the message and the document commit together.
 */
final readonly class AnalysisQueue
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function enqueue(Document $document): void
    {
        $document->markAnalysisQueued();

        $this->bus->dispatch(new StartAnalysis($document->getId(), $document->getCurrentAttempt()->getId()));
    }
}
