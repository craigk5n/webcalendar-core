<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;

final class EventTest extends TestCase
{
    public function testCanBeCreatedWithValidData(): void
    {
        $id = new EventId(1);
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        
        $event = new Event(
            id: $id,
            uid: 'test-uid-123',
            name: 'Test Event',
            description: 'This is a test event.',
            location: 'Office',
            start: $start,
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $this->assertEquals($id, $event->id());
        $this->assertSame('test-uid-123', $event->uid());
        $this->assertSame('Test Event', $event->name());
        $this->assertSame('This is a test event.', $event->description());
        $this->assertSame('Office', $event->location());
        $this->assertEquals($start, $event->start());
        $this->assertSame(60, $event->duration());
        $this->assertSame('admin', $event->createdBy());
        $this->assertSame(EventType::EVENT, $event->type());
        $this->assertSame(AccessLevel::PUBLIC, $event->access());
        $this->assertInstanceOf(\WebCalendar\Core\Domain\ValueObject\Recurrence::class, $event->recurrence());
        $this->assertFalse($event->recurrence()->isRepeating());
        
        // Calculated end time
        $expectedEnd = $start->modify('+60 minutes');
        $this->assertEquals($expectedEnd, $event->end());
    }

    public function testThrowsExceptionForEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Event(
            id: new EventId(1),
            uid: 'uid',
            name: '',
            description: '',
            location: '',
            start: new \DateTimeImmutable(),
            duration: 0,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );
    }

    public function testThrowsExceptionForNegativeDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Event(
            id: new EventId(1),
            uid: 'uid',
            name: 'Name',
            description: '',
            location: '',
            start: new \DateTimeImmutable(),
            duration: -1,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );
    }
}
