<?php

declare(strict_types=1);

namespace App\Analysis;

use Symfony\Component\Uid\Uuid;

/**
 * Asks the worker to start one analysis attempt. Carries the attempt so that a duplicate or late message
 * (another attempt is current, or the document moved on) is recognised and ignored.
 */
final readonly class AnalyzeDocument
{
    public function __construct(
        public Uuid $documentId,
        public Uuid $attemptId,
    ) {
    }
}
