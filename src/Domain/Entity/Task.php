<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\Recurrence;

/**
 * Domain entity representing a Calendar Task (VTODO).
 */
final readonly class Task extends AbstractEntry
{
    /**
     * @throws \InvalidArgumentException If name is empty, duration is negative, or percent is invalid.
     */
    public function __construct(
        EventId $id,
        string $uid,
        string $name,
        string $description,
        string $location,
        \DateTimeImmutable $start,
        int $duration,
        string $createdBy,
        EventType $type,
        AccessLevel $access,
        private ?\DateTimeImmutable $dueDate = null,
        private int $percentComplete = 0,
        Recurrence $recurrence = new Recurrence(),
        int $sequence = 0,
        ?string $status = null
    ) {
        parent::__construct(
            $id, $uid, $name, $description, $location, $start, $duration, 
            $createdBy, $type, $access, $recurrence, $sequence, $status
        );

        if ($this->percentComplete < 0 || $this->percentComplete > 100) {
            throw new \InvalidArgumentException('Percent complete must be between 0 and 100.');
        }
    }

    public function dueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function percentComplete(): int
    {
        return $this->percentComplete;
    }
}
