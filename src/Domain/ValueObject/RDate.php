<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Value object representing a list of Recurrence Dates (RDATE).
 */
final readonly class RDate
{
    /**
     * @param \DateTimeImmutable[] $dates
     */
    public function __construct(
        private array $dates = []
    ) {
    }

    /**
     * @return \DateTimeImmutable[]
     */
    public function dates(): array
    {
        return $this->dates;
    }

    public function isEmpty(): bool
    {
        return empty($this->dates);
    }
}
