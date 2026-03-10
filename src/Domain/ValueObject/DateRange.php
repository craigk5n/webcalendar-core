<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Value object representing a date range with a start and end date/time.
 */
final readonly class DateRange
{
    /**
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

    public function startDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

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
