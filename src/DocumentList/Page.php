<?php

declare(strict_types=1);

namespace App\DocumentList;

/**
 * One page of a paginated result: the items, plus what a pager needs to be drawn.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $number,
        public int $perPage,
    ) {
        if ($perPage < 1 || $number < 1 || $total < 0) {
            throw new \InvalidArgumentException('A page needs a positive number and size, and a non-negative total.');
        }
    }

    /**
     * Reads a page number from untrusted input: anything but a positive integer is page 1.
     */
    public static function parseNumber(mixed $raw): int
    {
        if (!\is_string($raw) || !preg_match('/^[0-9]{1,9}$/D', $raw)) {
            return 1;
        }

        return max(1, (int) $raw);
    }

    /**
     * Brings a requested page back into 1..pageCount: an out-of-range page shows the last one, never an error.
     */
    public static function clamp(int $requested, int $total, int $perPage): int
    {
        return max(1, min($requested, self::countPages($total, $perPage)));
    }

    public function pageCount(): int
    {
        return self::countPages($this->total, $this->perPage);
    }

    public function offset(): int
    {
        return ($this->number - 1) * $this->perPage;
    }

    /**
     * Position (1-based) of the first and the last item shown, e.g. "26 to 50 of 123".
     *
     * @return array{first: int, last: int}
     */
    public function range(): array
    {
        return [
            'first' => 0 === $this->total ? 0 : $this->offset() + 1,
            'last' => min($this->total, $this->offset() + $this->perPage),
        ];
    }

    public function hasPrevious(): bool
    {
        return $this->number > 1;
    }

    public function hasNext(): bool
    {
        return $this->number < $this->pageCount();
    }

    /**
     * Page numbers to link, compact: the first, the last and the neighbours of the current page. A gap of at least
     * two pages is shown as null (an ellipsis); a gap of a single page is filled in, it takes no more room.
     *
     * @return list<int|null>
     */
    public function window(): array
    {
        $last = $this->pageCount();
        $wanted = array_unique([1, $this->number - 1, $this->number, $this->number + 1, $last]);
        sort($wanted);
        $wanted = array_values(array_filter($wanted, static fn (int $number): bool => $number >= 1 && $number <= $last));

        $window = [];
        $previous = 0;
        foreach ($wanted as $number) {
            if (2 === $number - $previous) {
                $window[] = $previous + 1;
            } elseif ($number - $previous > 2) {
                $window[] = null;
            }
            $window[] = $number;
            $previous = $number;
        }

        return $window;
    }

    private static function countPages(int $total, int $perPage): int
    {
        return max(1, (int) ceil($total / $perPage));
    }
}
