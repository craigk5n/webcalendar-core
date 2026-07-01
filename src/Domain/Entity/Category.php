<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

/**
 * Domain entity representing an Event Category.
 */
final class Category
{
    public function __construct(
        private readonly int $id,
        private readonly ?string $owner,
        private readonly string $name,
        private readonly ?string $color,
        private readonly bool $enabled = true
    ) {
        if (empty(trim($this->name))) {
            throw new \InvalidArgumentException('Category name cannot be empty.');
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function owner(): ?string
    {
        return $this->owner;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function color(): ?string
    {
        return $this->color;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isGlobal(): bool
    {
        return $this->owner === null;
    }
}
