<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

/**
 * Domain entity representing an Event Category.
 */
final readonly class Category
{
    public function __construct(
        private int $id,
        private ?string $owner,
        private string $name,
        private ?string $color,
        private bool $enabled = true
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
