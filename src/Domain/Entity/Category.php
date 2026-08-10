<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

/**
 * Domain entity representing an Event Category.
 *
 * A category flagged `isTag` is a tag (Epic 23): a flat, global,
 * non-exclusive label with no owner-scoped color semantics. Tags share
 * categories' storage and event-assignment machinery but are kept out
 * of category listings.
 */
final class Category
{
    public function __construct(
        private readonly int $id,
        private readonly ?string $owner,
        private readonly string $name,
        private readonly ?string $color,
        private readonly bool $enabled = true,
        private readonly bool $isTag = false,
    ) {
        if (empty(trim($this->name))) {
            throw new \InvalidArgumentException('Category name cannot be empty.');
        }
        if ($this->isTag && $this->owner !== null) {
            throw new \InvalidArgumentException('Tags are global; they cannot have an owner.');
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

    public function isTag(): bool
    {
        return $this->isTag;
    }
}
