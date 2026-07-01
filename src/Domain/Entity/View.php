<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

use WebCalendar\Core\Domain\ValueObject\ViewType;

/**
 * Domain entity representing a Custom View.
 */
final class View
{
    public function __construct(
        private readonly int $id,
        private readonly string $owner,
        private readonly string $name,
        private readonly ViewType $type,
        private readonly bool $isGlobal = false
    ) {
        if (empty(trim($this->name))) {
            throw new \InvalidArgumentException('View name cannot be empty.');
        }
        if (empty(trim($this->owner))) {
            throw new \InvalidArgumentException('View owner cannot be empty.');
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

    public function type(): ViewType
    {
        return $this->type;
    }

    public function isGlobal(): bool
    {
        return $this->isGlobal;
    }
}
