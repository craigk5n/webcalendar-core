<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

use WebCalendar\Core\Domain\ValueObject\ViewType;

/**
 * Domain entity representing a Custom View.
 */
final readonly class View
{
    public function __construct(
        private int $id,
        private string $owner,
        private string $name,
        private ViewType $type,
        private bool $isGlobal = false
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
