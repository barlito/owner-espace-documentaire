<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\DocumentStatus;

/**
 * Raised when an action does not apply to the current document status
 * (e.g. retrying an analysis that is no longer failed).
 */
final class InvalidDocumentTransition extends \DomainException
{
    public function __construct(
        public readonly string $action,
        public readonly DocumentStatus $status,
    ) {
        parent::__construct(\sprintf('Cannot "%s" a document whose status is "%s".', $action, $status->value));
    }
}
