<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

use WebCalendar\Core\Domain\ValueObject\EventId;

/**
 * Domain entity representing a Calendar Event.
 */
final class Event extends AbstractEntry
{
    /**
     * Return a copy of this event carrying a different identity.
     *
     * Events mapped from iCal arrive with EventId(0), which the repository
     * treats as "insert". Re-pointing a mapped event at an existing row's id
     * turns that same save into an update, which is how import reconciles a
     * VEVENT whose UID it has already seen.
     *
     * Deliberately declared on Event rather than AbstractEntry: Task's
     * constructor has a different signature, so a `new static(...)` wither on
     * the base class would not be safe for every subclass.
     */
    public function withId(EventId $id): self
    {
        return new self(
            $id,
            $this->uid,
            $this->name,
            $this->description,
            $this->location,
            $this->start,
            $this->duration,
            $this->createdBy,
            $this->type,
            $this->access,
            $this->recurrence,
            $this->sequence,
            $this->status,
            $this->allDay,
            $this->modDate,
            $this->modTime,
            $this->image,
        );
    }
}
