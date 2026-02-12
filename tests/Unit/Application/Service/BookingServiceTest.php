<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\BookingService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventCollection;

final class BookingServiceTest extends TestCase
{
    /** @var EventRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventRepository;
    private BookingService $bookingService;

    protected function setUp(): void
    {
        $this->eventRepository = $this->createMock(EventRepositoryInterface::class);
        $eventService = new EventService($this->eventRepository);
        $this->bookingService = new BookingService($eventService);
    }

    public function testGetAvailabilityReturnsFreeSlots(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        $date = new \DateTimeImmutable('2026-02-11');

        // Mock office hours: 09:00 - 17:00
        // Mock existing event: 10:00 - 11:00
        $this->eventRepository->expects($this->once())
            ->method('findByDateRange')
            ->willReturn([]); // Empty for simple test

        $slots = $this->bookingService->getAvailability($user, $date);
        
        $this->assertNotEmpty($slots);
        // 09:00 to 17:00 is 8 hours = 16 slots.
        $this->assertCount(16, $slots);
    }

    public function testBookCreatesPendingEvent(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        
        $this->eventRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Event $event) use ($user) {
                return $event->status() === 'TENTATIVE' 
                    && $event->createdBy() === $user->login()
                    && str_starts_with($event->name(), 'Booking:');
            }));

        $this->bookingService->book($user, 'Alice', 'alice@example.com', $start, 60);
    }
}
