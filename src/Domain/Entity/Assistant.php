<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

/**
 * Domain entity representing an Assistant relationship.
 */
final readonly class Assistant
{
    public function __construct(
        private string $boss,
        private string $assistant
    ) {
        if (empty(trim($this->boss))) {
            throw new \InvalidArgumentException('Boss login cannot be empty.');
        }
        if (empty(trim($this->assistant))) {
            throw new \InvalidArgumentException('Assistant login cannot be empty.');
        }
    }

    public function boss(): string
    {
        return $this->boss;
    }

    public function assistant(): string
    {
        return $this->assistant;
    }
}
