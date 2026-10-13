<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Asks the worker to push the current state of one document to the browsers watching it.
 * Only the id travels: the handler renders what the document looks like when it runs, never a stale snapshot.
 */
final readonly class PublishDocumentUpdate
{
    /**
     * @param bool $created the document has just been received, so lists must add it instead of replacing a row
     */
    public function __construct(
        public Uuid $documentId,
        public bool $created = false,
    ) {
    }
}
