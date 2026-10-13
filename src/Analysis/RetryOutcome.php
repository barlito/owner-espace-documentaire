<?php

declare(strict_types=1);

namespace App\Analysis;

enum RetryOutcome
{
    /** A new attempt was created and queued. */
    case Retried;

    /** The document is not failed (any more): someone else already retried it, or the analysis succeeded meanwhile. */
    case AlreadyHandled;

    case NotFound;
}
