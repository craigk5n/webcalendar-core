<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Entity\Event;
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

    public function testGetEventsInDateRange(): void
    {
        $range = new DateRange(
            new \DateTimeImmutable('2026-02-11 00:00:00'),
            new \DateTimeImmutable('2026-02-11 23:59:59')
        );
        
        $user = new \WebCalendar\Core\Domain\Entity\User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        $events = []; // Mock events could go here
        
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
        $event = new Event(
            id: new EventId(1),
            uid: 'uid',
            name: 'Meeting',
            description: '',
            location: '',
            start: new \DateTimeImmutable(),
            duration: 30,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );
        
        $this->eventRepository->expects($this->once())
            ->method('save')
            ->with($event);

        $this->eventService->createEvent($event);
    }

    public function testDeleteEventThrowsExceptionIfNotFound(): void
    {
        $id = new EventId(999);
        
        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->with($id)
            ->willReturn(null);

        $this->expectException(\WebCalendar\Core\Domain\Exception\EventNotFoundException::class);
        $this->eventService->deleteEvent($id);
    }

    public function testUpdateEventThrowsExceptionIfNotFound(): void
    {
        $id = new EventId(999);
        $event = new Event(
            id: $id,
            uid: 'uid',
            name: 'Meeting',
            description: '',
            location: '',
            start: new \DateTimeImmutable(),
            duration: 30,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );
        
        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->with($id)
            ->willReturn(null);

        $this->expectException(\WebCalendar\Core\Domain\Exception\EventNotFoundException::class);
        $this->eventService->updateEvent($event);
    }

    public function testApproveEvent(): void
    {
        $id = new EventId(123);
        $login = 'jdoe';
        
        $this->eventRepository->expects($this->once())
            ->method('updateParticipantStatus')
            ->with($id, $login, 'A');

        $this->eventService->approveEvent($id, $login);
    }

    public function testRejectEvent(): void
    {
        $id = new EventId(123);
        $login = 'jdoe';
        
        $this->eventRepository->expects($this->once())
            ->method('updateParticipantStatus')
            ->with($id, $login, 'R');

        $this->eventService->rejectEvent($id, $login);
    }
}
