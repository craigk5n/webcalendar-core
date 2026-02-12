<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\Recurrence;

/**
 * Service for handling public scheduling/booking.
 */
final readonly class BookingService
{
    public function __construct(
        private EventService $eventService
    ) {
    }

    /**
     * Calculates available time slots for a specific user and date.
     * 
     * @return DateRange[]
     */
    public function getAvailability(User $user, \DateTimeImmutable $date): array
    {
        // Define office hours (could be loaded from user preferences)
        $workStart = $date->setTime(9, 0);
        $workEnd = $date->setTime(17, 0);
        $slotDuration = 30; // minutes

        // Get existing events for the date
        $range = new DateRange($date->setTime(0, 0), $date->setTime(23, 59, 59));
        $existingEvents = $this->eventService->getEventsInDateRange($range, $user);

        $slots = [];
        $current = $workStart;

        while ($current < $workEnd) {
            $next = $current->modify('+' . $slotDuration . ' minutes');
            $slotRange = new DateRange($current, $next);

            $hasConflict = false;
            foreach ($existingEvents as $event) {
                if ($slotRange->overlaps(new DateRange($event->start(), $event->end()))) {
                    $hasConflict = true;
                    break;
                }
            }

            if (!$hasConflict) {
                $slots[] = $slotRange;
            }

            $current = $next;
        }

        return $slots;
    }

    /**
     * Books an appointment.
     */
    public function book(User $user, string $name, string $email, \DateTimeImmutable $start, int $duration): void
    {
        // Create a pending event
        $event = new Event(
            id: new EventId(0),
            uid: bin2hex(random_bytes(16)),
            name: 'Booking: ' . $name,
            description: 'Booked by ' . $name . ' (' . $email . ')',
            location: '',
            start: $start,
            duration: $duration,
            createdBy: $user->login(),
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            recurrence: new Recurrence(),
            status: 'TENTATIVE' // Pending approval
        );

        $this->eventService->createEvent($event);
    }
}
