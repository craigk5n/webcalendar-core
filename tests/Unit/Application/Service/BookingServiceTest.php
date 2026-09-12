<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use WebCalendar\Core\Application\Service\BookingService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventCollection;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Application\Contract\HtmlSanitizerInterface;

final class BookingServiceTest extends TestCase
{
    /** @var EventRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventRepository;
    private BookingService $bookingService;

    protected function setUp(): void
    {
        $this->eventRepository = $this->createMock(EventRepositoryInterface::class);
        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $eventService = new EventService($this->eventRepository, $userRepository);
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

    public function testGetAvailabilitySkipsOnlyTheSlotsAnAppointmentActuallyCovers(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        $date = new \DateTimeImmutable('2026-02-11');

        // One 09:00-10:00 appointment covers exactly two 30-minute slots.
        // The 10:00-10:30 slot begins when the appointment ends, so it is free.
        $this->eventRepository->expects($this->once())
            ->method('findByDateRange')
            ->willReturn([$this->createEventAt($date->setTime(9, 0), 60)]);

        $slots = $this->bookingService->getAvailability($user, $date);

        $this->assertCount(14, $slots);
        $this->assertSame('10:00', $slots[0]->startDate()->format('H:i'));
    }

    public function testGetAvailabilityHonoursCustomOfficeHours(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        $date = new \DateTimeImmutable('2026-02-11');

        $this->eventRepository->method('findByDateRange')->willReturn([]);

        $slots = $this->bookingService->getAvailability(
            $user,
            $date,
            startHour: 8,
            endHour: 10,
            slotMinutes: 60
        );

        $this->assertCount(2, $slots);
        $this->assertSame('08:00', $slots[0]->startDate()->format('H:i'));
        $this->assertSame('09:00', $slots[1]->startDate()->format('H:i'));
    }

    public function testGetAvailabilityRejectsNonsensicalOfficeHours(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);

        $this->expectException(\InvalidArgumentException::class);
        $this->bookingService->getAvailability(
            $user,
            new \DateTimeImmutable('2026-02-11'),
            startHour: 17,
            endHour: 9
        );
    }

    public function testBookAcceptsTheCallersOwnPendingStatus(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');

        // "Pending approval" is the consuming application's vocabulary, not
        // this library's.  Core must not hard-code one project's spelling.
        $this->eventRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(
                static fn (Event $event): bool => $event->status() === 'needs_approval'
            ));

        $this->bookingService->book(
            $user,
            'Alice',
            'alice@example.com',
            $start,
            60,
            status: 'needs_approval'
        );
    }

    public function testBookSanitizesTheNameAndDescriptionItBuilds(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');

        // Both strings arrive from an anonymous booking form in the consumer.
        $this->eventRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(static function (Event $event): bool {
                self::assertStringNotContainsString('<script>', $event->name());
                self::assertStringNotContainsString('<script>', $event->description());
                self::assertStringNotContainsString('<img', $event->description());
                return true;
            }));

        $this->bookingService->book(
            $user,
            'Alice<script>alert(1)</script>',
            'alice@example.com<img src=x onerror=alert(1)>',
            $start,
            60
        );
    }

    public function testBookUsesAnInjectedSanitizerWhenGivenOne(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');

        $sanitizer = new class implements HtmlSanitizerInterface {
            public function sanitize(string $html): string
            {
                return '[clean]';
            }
        };

        $eventService = new EventService($this->eventRepository, $this->createMock(UserRepositoryInterface::class));
        $service = new BookingService($eventService, sanitizer: $sanitizer);

        $this->eventRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(static function (Event $event): bool {
                self::assertSame('Booking: [clean]', $event->name());
                self::assertSame('[clean]', $event->description());
                return true;
            }));

        $service->book($user, 'Alice', 'alice@example.com', $start, 60);
    }

    private function createEventAt(\DateTimeImmutable $start, int $duration): Event
    {
        return new Event(
            id: new EventId(1),
            uid: 'uid-1',
            name: 'Existing appointment',
            description: '',
            location: '',
            start: $start,
            duration: $duration,
            createdBy: 'jdoe',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );
    }

    /**
     * A slot that runs past closing time must not be offered.  With the
     * office hours hardcoded to 9-17 in 30-minute slots this could not
     * happen; now that all three are caller-supplied, any window that is not
     * an exact multiple of the slot length would overhang.
     */
    public function testGetAvailabilityNeverOffersASlotThatRunsPastClosingTime(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        $date = new \DateTimeImmutable('2026-02-11');

        $this->eventRepository->method('findByDateRange')->willReturn([]);

        // 8 hours does not divide into 45-minute slots.
        $slots = $this->bookingService->getAvailability(
            $user,
            $date,
            startHour: 9,
            endHour: 17,
            slotMinutes: 45
        );

        $this->assertCount(10, $slots);

        $closing = $date->setTime(17, 0);
        foreach ($slots as $slot) {
            $this->assertLessThanOrEqual(
                $closing->getTimestamp(),
                $slot->endDate()->getTimestamp(),
                'slot ending ' . $slot->endDate()->format('H:i') . ' runs past closing'
            );
        }
    }

    /**
     * The sanitized strings are what gets persisted; the log line must not
     * quietly reintroduce the raw ones next to them.
     */
    public function testBookDoesNotLogRawUntrustedInput(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');

        $logger = new class extends AbstractLogger {
            /** @var array<int, array<string, mixed>> */
            public array $contexts = [];

            /**
             * @param mixed $level
             * @param string|\Stringable $message
             * @param mixed[] $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };

        $eventService = new EventService(
            $this->eventRepository,
            $this->createMock(UserRepositoryInterface::class)
        );
        $service = new BookingService($eventService, $logger);

        $service->book(
            $user,
            'Alice<script>alert(1)</script>',
            'alice@example.com<img src=x onerror=alert(1)>',
            $start,
            60
        );

        $this->assertNotEmpty($logger->contexts);
        $serialized = json_encode($logger->contexts);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('<script', $serialized);
        $this->assertStringNotContainsString('<img', $serialized);
    }
}
