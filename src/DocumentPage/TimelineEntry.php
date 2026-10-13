<?php

declare(strict_types=1);

namespace App\DocumentPage;

/**
 * One line of a document timeline, already worded for its reader.
 */
final readonly class TimelineEntry
{
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public string $icon,
        public string $label,
        public ?string $detail = null,
        /** Badge variant giving the entry its colour (see the Badge component). */
        public string $variant = 'neutral',
    ) {
    }
}
