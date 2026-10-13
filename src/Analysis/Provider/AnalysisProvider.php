<?php

declare(strict_types=1);

namespace App\Analysis\Provider;

use App\Entity\Document;

/**
 * The service that actually analyses documents. Shaped after an asynchronous third-party provider (OCR, AI):
 * the file is submitted, the provider answers with a reference, and the outcome is fetched later with it.
 *
 * Only implementation today: Simulation\SimulatedAnalysisProvider. A real provider is one more implementation
 * wired in config/services.yaml (see the README, "Brancher un vrai fournisseur").
 *
 * Both methods run inside the worker's database transaction, with the document row locked: implementations
 * calling an HTTP API must use a short timeout.
 */
interface AnalysisProvider
{
    /**
     * Sends the document of the current attempt. Messenger may replay this call (for instance when the database
     * commit fails after a successful submission): implementations must use the attempt id as an idempotency key.
     *
     * @throws \Throwable on a technical error: the message is retried, then the attempt is failed
     */
    public function submit(Document $document): AnalysisSubmission;

    /**
     * Asks where the submission stands: still running, succeeded with a result, or failed with a reason.
     *
     * @throws \Throwable on a technical error: the message is retried, then the attempt is failed
     */
    public function fetchOutcome(Document $document, string $reference): AnalysisOutcome;
}
