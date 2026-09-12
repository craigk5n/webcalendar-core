<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Value object representing a date range with a start and end date/time.
 */
final class DateRange
{
    /**
     * @throws \InvalidArgumentException If start date is after end date.
     */
    public function __construct(
        private readonly \DateTimeImmutable $startDate,
        private readonly \DateTimeImmutable $endDate
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
     *
     * Ranges are half-open, [start, end): a range that begins exactly when
     * another ends does not overlap it.  A meeting ending at 10:00 does not
     * conflict with one starting at 10:00, and the 10:00 slot after an
     * appointment is bookable.  This matches ConflictDetector and RFC 5545,
     * where DTEND is exclusive.
     *
     * A consequence worth knowing: a zero-length range overlaps nothing.
     */
    public function overlaps(DateRange $other): bool
    {
        return $this->startDate < $other->endDate && $this->endDate > $other->startDate;
    }
}
