<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

/**
 * Domain entity representing a User Group.
 */
final readonly class Group
{
    public function __construct(
        private int $id,
        private string $owner,
        private string $name,
        private \DateTimeImmutable $lastUpdate
    ) {
        if (empty(trim($this->name))) {
            throw new \InvalidArgumentException('Group name cannot be empty.');
        }
        if (empty(trim($this->owner))) {
            throw new \InvalidArgumentException('Group owner cannot be empty.');
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function lastUpdate(): \DateTimeImmutable
    {
        return $this->lastUpdate;
    }
}
