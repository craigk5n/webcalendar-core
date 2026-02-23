<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Exception\EventNotFoundException;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;

final class EventServiceTest extends TestCase
{
    /** @var EventRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventRepository;
    private EventService $eventService;

    protected function setUp(): void
    {
        $this->eventRepository = $this->createMock(EventRepositoryInterface::class);
        $this->eventService = new EventService($this->eventRepository);
    }

    private function createUser(string $login, bool $isAdmin = false): User
    {
        return new User($login, 'First', 'Last', $login . '@example.com', $isAdmin, true);
    }

    private function createEvent(int $id, string $createdBy): Event
    {
        return new Event(
            id: new EventId($id),
            uid: 'uid-' . $id,
            name: 'Test Event',
            description: '',
            location: '',
            start: new \DateTimeImmutable(),
            duration: 60,
            createdBy: $createdBy,
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );
    }

    public function testGetEventsInDateRange(): void
    {
        $range = new DateRange(
            new \DateTimeImmutable('2026-02-11 00:00:00'),
            new \DateTimeImmutable('2026-02-11 23:59:59')
        );
        
        $user = $this->createUser('jdoe');
        $events = [];
        
        $this->eventRepository->expects($this->once())
            ->method('findByDateRange')
            ->with($range, $user)
            ->willReturn($events);

        $result = $this->eventService->getEventsInDateRange($range, $user);
        $this->assertInstanceOf(\WebCalendar\Core\Domain\ValueObject\EventCollection::class, $result);
        $this->assertSame($events, $result->all());
    }

    public function testCreateEvent(): void
    {
        $actor = $this->createUser('admin');
        $event = $this->createEvent(1, 'admin');
        
        $this->eventRepository->expects($this->once())
            ->method('save')
            ->with($event);

        $this->eventService->createEvent($event, $actor);
    }

    public function testDeleteEventThrowsExceptionIfNotFound(): void
    {
        $id = new EventId(999);
        $actor = $this->createUser('admin');
        
        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->with($id)
            ->willReturn(null);

        $this->expectException(EventNotFoundException::class);
        $this->eventService->deleteEvent($id, $actor);
    }

    public function testUpdateEventThrowsExceptionIfNotFound(): void
    {
        $id = new EventId(999);
        $actor = $this->createUser('admin');
        $event = $this->createEvent(999, 'admin');
        
        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->with($id)
            ->willReturn(null);

        $this->expectException(EventNotFoundException::class);
        $this->eventService->updateEvent($event, $actor);
    }

    public function testOwnerCanDeleteOwnEvent(): void
    {
        $event = $this->createEvent(1, 'jdoe');
        $actor = $this->createUser('jdoe');
        
        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);
        
        $this->eventRepository->expects($this->once())
            ->method('delete');

        $this->eventService->deleteEvent(new EventId(1), $actor);
    }

    public function testOwnerCanUpdateOwnEvent(): void
    {
        $event = $this->createEvent(1, 'jdoe');
        $actor = $this->createUser('jdoe');
        
        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);
        
        $this->eventRepository->expects($this->once())
            ->method('save');

        $this->eventService->updateEvent($event, $actor);
    }

    public function testAdminCanDeleteAnyEvent(): void
    {
        $event = $this->createEvent(1, 'otheruser');
        $admin = $this->createUser('admin', isAdmin: true);
        
        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);
        
        $this->eventRepository->expects($this->once())
            ->method('delete');

        $this->eventService->deleteEvent(new EventId(1), $admin);
    }

    public function testAdminCanUpdateAnyEvent(): void
    {
        $event = $this->createEvent(1, 'otheruser');
        $admin = $this->createUser('admin', isAdmin: true);
        
        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);
        
        $this->eventRepository->expects($this->once())
            ->method('save');

        $this->eventService->updateEvent($event, $admin);
    }

    public function testNonOwnerCannotDeleteOthersEvent(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('attacker');
        
        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);
        
        $this->eventRepository->expects($this->never())
            ->method('delete');

        $this->expectException(AuthorizationException::class);
        $this->eventService->deleteEvent(new EventId(1), $actor);
    }

    public function testNonOwnerCannotUpdateOthersEvent(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('attacker');
        
        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);
        
        $this->eventRepository->expects($this->never())
            ->method('save');

        $this->expectException(AuthorizationException::class);
        $this->eventService->updateEvent($event, $actor);
    }

    public function testUserCanApproveForThemselves(): void
    {
        $actor = $this->createUser('jdoe');
        
        $this->eventRepository->expects($this->once())
            ->method('updateParticipantStatus')
            ->with(new EventId(123), 'jdoe', 'A');

        $this->eventService->approveEvent(new EventId(123), 'jdoe', $actor);
    }

    public function testUserCanRejectForThemselves(): void
    {
        $actor = $this->createUser('jdoe');
        
        $this->eventRepository->expects($this->once())
            ->method('updateParticipantStatus')
            ->with(new EventId(123), 'jdoe', 'R');

        $this->eventService->rejectEvent(new EventId(123), 'jdoe', $actor);
    }

    public function testAdminCanApproveForAnyone(): void
    {
        $admin = $this->createUser('admin', isAdmin: true);
        
        $this->eventRepository->expects($this->once())
            ->method('updateParticipantStatus')
            ->with(new EventId(123), 'jdoe', 'A');

        $this->eventService->approveEvent(new EventId(123), 'jdoe', $admin);
    }

    public function testUserCannotApproveForOthers(): void
    {
        $actor = $this->createUser('attacker');
        
        $this->eventRepository->expects($this->never())
            ->method('updateParticipantStatus');

        $this->expectException(AuthorizationException::class);
        $this->eventService->approveEvent(new EventId(123), 'victim', $actor);
    }

    public function testUserCannotRejectForOthers(): void
    {
        $actor = $this->createUser('attacker');
        
        $this->eventRepository->expects($this->never())
            ->method('updateParticipantStatus');

        $this->expectException(AuthorizationException::class);
        $this->eventService->rejectEvent(new EventId(123), 'victim', $actor);
    }
}
