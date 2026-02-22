<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

/**
 * Domain entity representing an event Reminder (VALARM).
 */
final readonly class Reminder
{
    public function __construct(
        private int $eventId,
        private int $date = 0,
        private int $offset = 0,
        private string $related = 'S',
        private string $before = 'Y',
        private int $lastSent = 0,
        private int $repeats = 0,
        private int $duration = 0,
        private int $timesSent = 0,
        private string $action = 'EMAIL',
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
