<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Enum\DocumentType;

/**
 * What a successful analysis extracted from a document.
 *
 * @phpstan-type Field array{label: string, value: string}
 */
final readonly class AnalysisResult
{
    /**
     * @param list<Field> $fields
     */
    public function __construct(
        public ?DocumentType $detectedType,
        public ?\DateTimeImmutable $periodStart,
        public ?\DateTimeImmutable $periodEnd,
        public string $summary,
        public array $fields,
        public float $confidence,
    ) {
    }

    /**
     * JSON stored on the attempt (snake_case keys, dates as Y-m-d).
     *
     * @return array{detected_type: ?string, period: ?array{start: string, end: string}, summary: string, fields: list<Field>, confidence: float}
     */
    public function toArray(): array
    {
        return [
            'detected_type' => $this->detectedType?->value,
            'period' => null === $this->periodStart || null === $this->periodEnd ? null : [
                'start' => $this->periodStart->format('Y-m-d'),
                'end' => $this->periodEnd->format('Y-m-d'),
            ],
            'summary' => $this->summary,
            'fields' => $this->fields,
            'confidence' => $this->confidence,
        ];
    }
}
