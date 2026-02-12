<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

/**
 * Domain entity representing a Calendar Layer (overlay).
 */
final readonly class Layer
{
    public function __construct(
        private int $id,
        private string $owner,
        private string $layerUser,
        private string $color,
        private bool $showDuplicates = false
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
