<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

/**
 * Domain entity representing a Calendar Layer (overlay).
 */
final class Layer
{
    public function __construct(
        private readonly int $id,
        private readonly string $owner,
        private readonly string $layerUser,
        private readonly string $color,
        private readonly bool $showDuplicates = false
    ) {
        if (empty(trim($this->owner))) {
            throw new \InvalidArgumentException('Layer owner cannot be empty.');
        }
        if (empty(trim($this->layerUser))) {
            throw new \InvalidArgumentException('Layer user cannot be empty.');
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

    public function layerUser(): string
    {
        return $this->layerUser;
    }

    public function color(): string
    {
        return $this->color;
    }

    public function showDuplicates(): bool
    {
        return $this->showDuplicates;
    }
}
