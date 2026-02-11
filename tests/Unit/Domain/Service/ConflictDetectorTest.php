<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Service\ConflictDetector;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventCollection;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;

final class ConflictDetectorTest extends TestCase
{
    private ConflictDetector $conflictDetector;

    protected function setUp(): void
    {
        $this->conflictDetector = new ConflictDetector();
    }

    public function testDetectsExactOverlap(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $event = $this->createEvent(1, $start, 60);
        
        $existing = new EventCollection([
            $this->createEvent(2, $start, 60)
        ]);

        $conflicts = $this->conflictDetector->detectConflicts($event, $existing);
        $this->assertCount(1, $conflicts);
    }

    public function testDetectsPartialOverlap(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $event = $this->createEvent(1, $start, 60); // 10:00 - 11:00
        
        $existing = new EventCollection([
            $this->createEvent(2, $start->modify('-30 minutes'), 60), // 09:30 - 10:30
            $this->createEvent(3, $start->modify('+30 minutes'), 60)  // 10:30 - 11:30
        ]);

        $conflicts = $this->conflictDetector->detectConflicts($event, $existing);
        $this->assertCount(2, $conflicts);
    }

    public function testDoesNotDetectAdjacentEventsAsConflict(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $event = $this->createEvent(1, $start, 60); // 10:00 - 11:00
        
        $existing = new EventCollection([
            $this->createEvent(2, $start->modify('-60 minutes'), 60), // 09:00 - 10:00
            $this->createEvent(3, $start->modify('+60 minutes'), 60)  // 11:00 - 12:00
        ]);

        $conflicts = $this->conflictDetector->detectConflicts($event, $existing);
        $this->assertCount(0, $conflicts);
    }

    public function testDoesNotDetectConflictWhenLimitIsZero(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $event = $this->createEvent(1, $start, 60);
        
        $existing = new EventCollection([
            $this->createEvent(2, $start, 60)
        ]);

        $conflicts = $this->conflictDetector->detectConflicts($event, $existing, 0);
        $this->assertCount(0, $conflicts);
    }

    private function createEvent(int $id, \DateTimeImmutable $start, int $duration): Event
    {
        return new Event(
            id: new EventId($id),
            uid: 'uid-' . $id,
            name: 'Event ' . $id,
            description: '',
            location: '',
            start: $start,
            duration: $duration,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );
    }
}
