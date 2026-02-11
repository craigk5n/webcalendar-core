<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Value object representing a date range with a start and end date/time.
 */
final readonly class DateRange
{
    /**
     * @param \DateTimeImmutable $startDate The start of the range.
     * @param \DateTimeImmutable $endDate The end of the range. Must be after or equal to start.
     * @throws \InvalidArgumentException If start date is after end date.
     */
    public function __construct(
        private \DateTimeImmutable $startDate,
        private \DateTimeImmutable $endDate
    ) {
        if ($this->startDate > $this->endDate) {
            throw new \InvalidArgumentException('Start date cannot be after end date.');
        }
    }

    /**
     * Returns the start date.
     */
    public function startDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    /**
     * Returns the end date.
     */
    public function endDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    /**
     * Checks if the given date is within the range (inclusive).
     */
    public function contains(\DateTimeInterface $date): bool
    {
        return $date >= $this->startDate && $date <= $this->endDate;
    }

    /**
     * Checks if this range overlaps with another range.
     */
    public function overlaps(DateRange $other): bool
    {
        return $this->startDate <= $other->endDate && $this->endDate >= $other->startDate;
    }
}
