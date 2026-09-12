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
use WebCalendar\Core\Application\Contract\HtmlSanitizerInterface;
use WebCalendar\Core\Infrastructure\Security\PlainTextHtmlSanitizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for handling public scheduling/booking.
 */
final class BookingService
{
    /**
     * Status written for a new booking when the caller names none.
     *
     * RFC 5545's own word for "not yet confirmed".  It is deliberately not an
     * approval-queue state: this library has no opinion about what a pending
     * booking is called downstream -- see {@see book()}.
     */
    public const DEFAULT_STATUS = 'TENTATIVE';

    private readonly LoggerInterface $logger;
    private readonly HtmlSanitizerInterface $sanitizer;

    public function __construct(
        private readonly EventService $eventService,
        ?LoggerInterface $logger = null,
        ?HtmlSanitizerInterface $sanitizer = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->sanitizer = $sanitizer ?? new PlainTextHtmlSanitizer();
    }

    /**
     * Calculates available time slots for a specific user and date.
     *
     * Office hours are the caller's to supply; this library has no access to
     * the user's preferences.  The slots are built in $date's own timezone,
     * so pass a $date constructed in the timezone the answer should be read
     * in -- a UTC $date yields UTC office hours.
     *
     * @param int $startHour   First hour of the working day, 0-23.
     * @param int $endHour     Hour the working day ends, 1-24, after $startHour.
     * @param int $slotMinutes Length of each slot in minutes.
     * @return DateRange[]
     * @throws \InvalidArgumentException If the office hours are not a valid window.
     */
    public function getAvailability(
        User $user,
        \DateTimeImmutable $date,
        int $startHour = 9,
        int $endHour = 17,
        int $slotMinutes = 30
    ): array {
        if ($startHour < 0 || $startHour > 23) {
            throw new \InvalidArgumentException('Start hour must be between 0 and 23.');
        }
        if ($endHour < 1 || $endHour > 24) {
            throw new \InvalidArgumentException('End hour must be between 1 and 24.');
        }
        if ($endHour <= $startHour) {
            throw new \InvalidArgumentException('End hour must be after start hour.');
        }
        if ($slotMinutes < 1) {
            throw new \InvalidArgumentException('Slot length must be at least one minute.');
        }

        $workStart = $date->setTime($startHour, 0);
        // setTime(24, 0) rolls into the next day, which is what midnight means.
        $workEnd = $date->setTime($endHour, 0);
        $slotDuration = $slotMinutes;

        // Get existing events for the date
        $range = new DateRange($date->setTime(0, 0), $date->setTime(23, 59, 59));
        $existingEvents = $this->eventService->getEventsInDateRange($range, $user);

        $slots = [];
        $current = $workStart;

        while ($current < $workEnd) {
            $next = $current->modify('+' . $slotDuration . ' minutes');

            // A window that is not a whole number of slots long would
            // otherwise offer a final slot running past closing time.
            if ($next > $workEnd) {
                break;
            }

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
     *
     * $name and $email are untrusted -- in a typical consumer they arrive from
     * an anonymous booking form -- so both are passed through the sanitizer
     * before they are stored and later rendered in the owner's calendar.
     *
     * $status lets the caller name the state in its own vocabulary.  An
     * approval queue that lists, say, 'needs_approval' will never see a
     * booking left at {@see DEFAULT_STATUS}, so a consumer with an approval
     * workflow should pass the status that workflow actually reads.
     *
     * @param string|null $status Status to store; defaults to {@see DEFAULT_STATUS}.
     */
    public function book(
        User $user,
        string $name,
        string $email,
        \DateTimeImmutable $start,
        int $duration,
        ?string $status = null
    ): void {
        $safeName = $this->sanitizer->sanitize($name);
        $safeDescription = $this->sanitizer->sanitize(
            'Booked by ' . $name . ' (' . $email . ')'
        );

        // Create a pending event
        $event = new Event(
            id: new EventId(0),
            uid: bin2hex(random_bytes(16)),
            name: 'Booking: ' . $safeName,
            description: $safeDescription,
            location: '',
            start: $start,
            duration: $duration,
            createdBy: $user->login(),
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            recurrence: new Recurrence(),
            status: $status ?? self::DEFAULT_STATUS
        );

        // The sanitized strings are what gets stored; logging the raw ones
        // here would hand the payload straight to any log viewer that
        // renders HTML, undoing the sanitizing two lines above.
        $this->logger->info('Booking created', [
            'name' => $safeName,
            'email' => $this->sanitizer->sanitize($email),
            'start' => $start->format('Y-m-d H:i'),
            'duration' => $duration,
            'user' => $user->login(),
            'status' => $event->status()
        ]);

        $this->eventService->createEvent($event, $user);
    }
}
