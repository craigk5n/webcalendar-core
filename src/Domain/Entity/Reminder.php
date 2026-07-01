<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

/**
 * Domain entity representing an event Reminder (VALARM).
 */
final class Reminder
{
    public function __construct(
        private readonly int $eventId,
        private readonly int $date = 0,
        private readonly int $offset = 0,
        private readonly string $related = 'S',
        private readonly string $before = 'Y',
        private readonly int $lastSent = 0,
        private readonly int $repeats = 0,
        private readonly int $duration = 0,
        private readonly int $timesSent = 0,
        private readonly string $action = 'EMAIL',
    ) {
    }

    public function eventId(): int
    {
        return $this->eventId;
    }

    public function date(): int
    {
        return $this->date;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function related(): string
    {
        return $this->related;
    }

    public function before(): string
    {
        return $this->before;
    }

    public function lastSent(): int
    {
        return $this->lastSent;
    }

    public function repeats(): int
    {
        return $this->repeats;
    }

    public function duration(): int
    {
        return $this->duration;
    }

    public function timesSent(): int
    {
        return $this->timesSent;
    }

    public function action(): string
    {
        return $this->action;
    }
}
