<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Value object representing a unique Event identifier.
 */
final readonly class EventId
{
    /**
     * @param int $value The unique identifier value. Must be non-negative.
     * @throws \InvalidArgumentException If value is negative.
     */
    public function __construct(
        private int $value
    ) {
        if ($this->value < 0) {
            throw new \InvalidArgumentException('Event ID must be a non-negative integer.');
        }
    }

    /**
     * Returns the raw integer value.
     */
    public function value(): int
    {
        return $this->value;
    }

    /**
     * Compares this EventId with another for equality.
     */
    public function equals(EventId $other): bool
    {
        return $this->value === $other->value;
    }
}
