<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Value object representing a unique Organizer identifier.
 *
 * Id 0 means "not yet persisted", mirroring EventId's convention.
 */
final class OrganizerId
{
    /**
     * @throws \InvalidArgumentException If value is negative.
     */
    public function __construct(
        private readonly int $value
    ) {
        if ($this->value < 0) {
            throw new \InvalidArgumentException('Organizer ID must be a non-negative integer.');
        }
    }

    public function value(): int
    {
        return $this->value;
    }

    public function equals(OrganizerId $other): bool
    {
        return $this->value === $other->value;
    }
}
