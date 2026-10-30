<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use WebCalendar\Core\Application\Service\BookingService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Infrastructure\Persistence\PdoEventRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoUserRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

/**
 * A user's free slots depend on that user's calendar only: another user's
 * public entry must not block them, and the user's own private entry must.
 */
final class BookingAvailabilityScopeTest extends RepositoryTestCase
{
    private PdoEventRepository $events;
    private BookingService $booking;
    private User $alice;
    private User $bob;
    private \DateTimeImmutable $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new PdoEventRepository($this->pdo);
        $eventService = new EventService($this->events, new PdoUserRepository($this->pdo));
        $this->booking = new BookingService($eventService);
        $this->alice = new User('alice', 'A', 'A', 'alice@example.com', false, true);
        $this->bob = new User('bob', 'B', 'B', 'bob@example.com', false, true);
        $this->day = new \DateTimeImmutable('2030-03-04 00:00:00', new \DateTimeZone('UTC'));
    }

    public function testAnotherUsersBookingLeavesThisUsersSlotsAlone(): void
    {
        $this->assertCount(16, $this->booking->getAvailability($this->alice, $this->day));

        // An all-day public booking on bob's calendar.
        $this->booking->book($this->bob, 'Guest', 'guest@example.com', $this->day, 1439);

        $this->assertCount(16, $this->booking->getAvailability($this->alice, $this->day));
        $this->assertCount(0, $this->booking->getAvailability($this->bob, $this->day));
    }

    public function testTheUsersOwnPrivateEntryStillBlocksItsSlots(): void
    {
        $this->events->save(new Event(
            id: new EventId(0),
            uid: 'alice-private',
            name: 'Dentist',
            description: '',
            location: '',
            start: $this->day->setTime(9, 0),
            duration: 60,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PRIVATE,
        ));

        $this->assertCount(14, $this->booking->getAvailability($this->alice, $this->day));
        $this->assertCount(16, $this->booking->getAvailability($this->bob, $this->day));
    }

    public function testAnEventTheUserAttendsBlocksUnlessDeclined(): void
    {
        $this->events->save(new Event(
            id: new EventId(0),
            uid: 'bob-meeting',
            name: 'Planning',
            description: '',
            location: '',
            start: $this->day->setTime(9, 0),
            duration: 60,
            createdBy: 'bob',
            type: EventType::EVENT,
            access: AccessLevel::PRIVATE,
        ));
        $meeting = $this->events->findByUid('bob-meeting');
        $this->assertNotNull($meeting);

        $this->events->saveParticipantsWithStatus($meeting->id(), ['bob' => 'A', 'alice' => 'W']);
        $this->assertCount(14, $this->booking->getAvailability($this->alice, $this->day));

        $this->events->saveParticipantsWithStatus($meeting->id(), ['bob' => 'A', 'alice' => 'R']);
        $this->assertCount(16, $this->booking->getAvailability($this->alice, $this->day));
    }
}
