<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\OrganizerId;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\VenueId;

/**
 * Base class for all calendar entries (Events, Tasks, Journals).
 */
abstract class AbstractEntry
{
    /**
     * @throws \InvalidArgumentException If name is empty or duration is negative.
     */
    public function __construct(
        protected readonly EventId $id,
        protected readonly string $uid,
        protected readonly string $name,
        protected readonly string $description,
        protected readonly string $location,
        protected readonly \DateTimeImmutable $start,
        protected readonly int $duration,
        protected readonly string $createdBy,
        protected readonly EventType $type,
        protected readonly AccessLevel $access,
        protected readonly Recurrence $recurrence = new Recurrence(),
        protected readonly int $sequence = 0,
        protected readonly ?string $status = null,
        protected readonly bool $allDay = false,
        protected readonly ?int $modDate = null,
        protected readonly ?int $modTime = null,
        protected readonly ?string $image = null,
        protected readonly ?VenueId $venueId = null,
        protected readonly ?OrganizerId $organizerId = null,
        protected readonly ?string $conferenceUrl = null,
        protected readonly ?string $conferenceLabel = null,
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

    /**
     * RFC 7986 IMAGE property — URL of an image associated with this entry.
     */
    public function image(): ?string
    {
        return $this->image;
    }

    public function venueId(): ?VenueId
    {
        return $this->venueId;
    }

    public function organizerId(): ?OrganizerId
    {
        return $this->organizerId;
    }

    /**
     * Virtual/hybrid meeting URL (RFC 7986 CONFERENCE) — Epic 26.
     */
    public function conferenceUrl(): ?string
    {
        return $this->conferenceUrl;
    }

    /**
     * Display label for the meeting link (e.g. "Zoom", "Google Meet").
     */
    public function conferenceLabel(): ?string
    {
        return $this->conferenceLabel;
    }

    public function end(): \DateTimeImmutable
    {
        return $this->start->modify(sprintf('+%d minutes', $this->duration));
    }
}
