<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\OrganizerId;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\Unchanged;
use WebCalendar\Core\Domain\ValueObject\VenueId;

/**
 * Domain entity representing a Calendar Event.
 */
final class Event extends AbstractEntry
{
    /**
     * Return a copy of this event with the supplied fields replaced.
     *
     * Use this for any "the same event, but ..." change. Repositories save
     * entities as whole rows, so an event rebuilt with a bare constructor
     * call silently resets every field the caller did not think to pass —
     * and every field added to the constructor afterwards. That is not a
     * hypothetical: it cost venues, organizers and the moderation status in
     * consuming applications, with no test or type error to show for it.
     *
     * Omitted fields are carried over; passing null clears a nullable field
     * (see {@see Unchanged} for why the two are distinguishable).
     *
     *     $renamed = $event->with(name: 'New name');
     *     $unlinked = $event->with(venueId: null);
     *
     * Declared on Event rather than AbstractEntry because Task adds its own
     * constructor parameters, so a `new static(...)` copy on the base class
     * would silently drop them.
     *
     * @throws \InvalidArgumentException If the result would be invalid.
     */
    public function with(
        EventId|Unchanged $id = Unchanged::Value,
        string|Unchanged $uid = Unchanged::Value,
        string|Unchanged $name = Unchanged::Value,
        string|Unchanged $description = Unchanged::Value,
        string|Unchanged $location = Unchanged::Value,
        \DateTimeImmutable|Unchanged $start = Unchanged::Value,
        int|Unchanged $duration = Unchanged::Value,
        string|Unchanged $createdBy = Unchanged::Value,
        EventType|Unchanged $type = Unchanged::Value,
        AccessLevel|Unchanged $access = Unchanged::Value,
        Recurrence|Unchanged $recurrence = Unchanged::Value,
        int|Unchanged $sequence = Unchanged::Value,
        string|null|Unchanged $status = Unchanged::Value,
        bool|Unchanged $allDay = Unchanged::Value,
        int|null|Unchanged $modDate = Unchanged::Value,
        int|null|Unchanged $modTime = Unchanged::Value,
        string|null|Unchanged $image = Unchanged::Value,
        VenueId|null|Unchanged $venueId = Unchanged::Value,
        OrganizerId|null|Unchanged $organizerId = Unchanged::Value,
        string|null|Unchanged $conferenceUrl = Unchanged::Value,
        string|null|Unchanged $conferenceLabel = Unchanged::Value,
    ): self {
        return new self(
            id: $id instanceof Unchanged ? $this->id : $id,
            uid: $uid instanceof Unchanged ? $this->uid : $uid,
            name: $name instanceof Unchanged ? $this->name : $name,
            description: $description instanceof Unchanged ? $this->description : $description,
            location: $location instanceof Unchanged ? $this->location : $location,
            start: $start instanceof Unchanged ? $this->start : $start,
            duration: $duration instanceof Unchanged ? $this->duration : $duration,
            createdBy: $createdBy instanceof Unchanged ? $this->createdBy : $createdBy,
            type: $type instanceof Unchanged ? $this->type : $type,
            access: $access instanceof Unchanged ? $this->access : $access,
            recurrence: $recurrence instanceof Unchanged ? $this->recurrence : $recurrence,
            sequence: $sequence instanceof Unchanged ? $this->sequence : $sequence,
            status: $status instanceof Unchanged ? $this->status : $status,
            allDay: $allDay instanceof Unchanged ? $this->allDay : $allDay,
            modDate: $modDate instanceof Unchanged ? $this->modDate : $modDate,
            modTime: $modTime instanceof Unchanged ? $this->modTime : $modTime,
            image: $image instanceof Unchanged ? $this->image : $image,
            venueId: $venueId instanceof Unchanged ? $this->venueId : $venueId,
            organizerId: $organizerId instanceof Unchanged ? $this->organizerId : $organizerId,
            conferenceUrl: $conferenceUrl instanceof Unchanged ? $this->conferenceUrl : $conferenceUrl,
            conferenceLabel: $conferenceLabel instanceof Unchanged
                ? $this->conferenceLabel
                : $conferenceLabel,
        );
    }

    /**
     * Return a copy of this event carrying a different identity.
     *
     * Events mapped from iCal arrive with EventId(0), which the repository
     * treats as "insert". Re-pointing a mapped event at an existing row's id
     * turns that same save into an update, which is how import reconciles a
     * VEVENT whose UID it has already seen.
     */
    public function withId(EventId $id): self
    {
        return $this->with(id: $id);
    }

    /**
     * A copy pointing at a (persisted) venue — how import paths link a
     * mapped event to its matchOrCreate'd venue.
     */
    public function withVenueId(?VenueId $venueId): self
    {
        return $this->with(venueId: $venueId);
    }

    /**
     * A copy pointing at a (persisted) organizer.
     */
    public function withOrganizerId(?OrganizerId $organizerId): self
    {
        return $this->with(organizerId: $organizerId);
    }
}
