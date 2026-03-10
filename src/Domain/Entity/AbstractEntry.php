<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\Recurrence;

/**
 * Base class for all calendar entries (Events, Tasks, Journals).
 */
abstract readonly class AbstractEntry
{
    /**
     * @throws \InvalidArgumentException If name is empty or duration is negative.
     */
    public function __construct(
        protected EventId $id,
        protected string $uid,
        protected string $name,
        protected string $description,
        protected string $location,
        protected \DateTimeImmutable $start,
        protected int $duration,
        protected string $createdBy,
        protected EventType $type,
        protected AccessLevel $access,
        protected Recurrence $recurrence = new Recurrence(),
        protected int $sequence = 0,
        protected ?string $status = null,
        protected bool $allDay = false,
        protected ?int $modDate = null,
        protected ?int $modTime = null,
    ) {
        if (empty(trim($this->name))) {
            throw new \InvalidArgumentException('Name cannot be empty.');
        }

        if ($this->duration < 0) {
            throw new \InvalidArgumentException('Duration cannot be negative.');
        }
    }

    public function id(): EventId
    {
        return $this->id;
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function location(): string
    {
        return $this->location;
    }

    public function start(): \DateTimeImmutable
    {
        return $this->start;
    }

    public function duration(): int
    {
        return $this->duration;
    }

    public function createdBy(): string
    {
        return $this->createdBy;
    }

    public function type(): EventType
    {
        return $this->type;
    }

    public function access(): AccessLevel
    {
        return $this->access;
    }

    public function recurrence(): Recurrence
    {
        return $this->recurrence;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function status(): ?string
    {
        return $this->status;
    }

    /**
     * Whether this is an all-day event (RFC 5545 VALUE=DATE).
     */
    public function isAllDay(): bool
    {
        return $this->allDay;
    }

    public function modDate(): ?int
    {
        return $this->modDate;
    }

    public function modTime(): ?int
    {
        return $this->modTime;
    }

    public function end(): \DateTimeImmutable
    {
        return $this->start->modify(sprintf('+%d minutes', $this->duration));
    }
}
