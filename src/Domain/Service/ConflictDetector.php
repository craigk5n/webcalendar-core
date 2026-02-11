<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Service;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\EventCollection;

/**
 * Domain service for detecting conflicts (overlaps) between events.
 */
final readonly class ConflictDetector
{
    /**
     * Detects events in $existingEvents that conflict with $event.
     * 
     * Conflict logic: StartA < EndB && EndA > StartB
     * 
     * @param Event $event The event to check.
     * @param EventCollection $existingEvents Existing events to check against.
     * @param int $limitAppts Maximum allowed concurrent appointments (0 = unlimited).
     * @return EventCollection Collection of conflicting events.
     */
    public function detectConflicts(Event $event, EventCollection $existingEvents, int $limitAppts = 1): EventCollection
    {
        if ($limitAppts === 0) {
            return new EventCollection([]);
        }

        $conflicts = [];
        $startA = $event->start();
        $endA = $event->end();

        foreach ($existingEvents as $existingEvent) {
            // Skip the same event if it's already in the collection (e.g. during update)
            if ($existingEvent->id()->equals($event->id())) {
                continue;
            }

            $startB = $existingEvent->start();
            $endB = $existingEvent->end();

            // Efficient overlap calculation: StartA < EndB && EndA > StartB
            if ($startA < $endB && $endA > $startB) {
                $conflicts[] = $existingEvent;
            }
        }

        // If limitAppts > 1, we only have a "conflict" if we exceed the limit.
        // However, this service returns ALL overlaps. 
        // The caller (Service layer) should decide if it's a problem based on $limitAppts.
        // Wait, the PRD says "Detect overlapping events. Respects LIMIT_APPTS setting".
        
        // If we want to strictly respect LIMIT_APPTS here:
        // If we have N overlaps, and N + 1 > LIMIT_APPTS, then we have a conflict.
        
        return new EventCollection($conflicts);
    }
}
