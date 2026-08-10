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
    public function testWithIdReturnsACopyCarryingTheNewId(): void
    {
        $start = new \DateTimeImmutable('2026-07-15 14:46:00');

        $event = new Event(
            id: new EventId(0),
            uid: 'uid-abc',
            name: 'Imported Event',
            description: 'desc',
            location: 'loc',
            start: $start,
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            sequence: 3,
            status: 'A',
            allDay: true,
        );

        $copy = $event->withId(new EventId(42));

        $this->assertSame(42, $copy->id()->value());

        // Every other field survives the copy.
        $this->assertSame('uid-abc', $copy->uid());
        $this->assertSame('Imported Event', $copy->name());
        $this->assertSame('desc', $copy->description());
        $this->assertSame('loc', $copy->location());
        $this->assertEquals($start, $copy->start());
        $this->assertSame(60, $copy->duration());
        $this->assertSame('admin', $copy->createdBy());
        $this->assertSame(EventType::EVENT, $copy->type());
        $this->assertSame(AccessLevel::PUBLIC, $copy->access());
        $this->assertSame(3, $copy->sequence());
        $this->assertSame('A', $copy->status());
        $this->assertTrue($copy->isAllDay());
    }

    public function testWithIdDoesNotMutateTheOriginal(): void
    {
        $event = new Event(
            id: new EventId(7),
            uid: 'uid',
            name: 'Name',
            description: '',
            location: '',
            start: new \DateTimeImmutable(),
            duration: 0,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $event->withId(new EventId(99));

        $this->assertSame(7, $event->id()->value());
    }

    public function testEventCarriesOptionalVenueAndOrganizerReferences(): void
    {
        $event = new Event(
            id: new EventId(1),
            uid: 'uid',
            name: 'Placed Event',
            description: '',
            location: 'Community Hall',
            start: new \DateTimeImmutable(),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            venueId: new \WebCalendar\Core\Domain\ValueObject\VenueId(5),
            organizerId: new \WebCalendar\Core\Domain\ValueObject\OrganizerId(3),
        );

        $this->assertSame(5, $event->venueId()?->value());
        $this->assertSame(3, $event->organizerId()?->value());
        // The legacy location string is retained alongside the reference.
        $this->assertSame('Community Hall', $event->location());
    }

    public function testVenueAndOrganizerReferencesDefaultToNull(): void
    {
        $event = new Event(
            id: new EventId(1),
            uid: 'uid',
            name: 'Plain Event',
            description: '',
            location: '',
            start: new \DateTimeImmutable(),
            duration: 0,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $this->assertNull($event->venueId());
        $this->assertNull($event->organizerId());
    }

    public function testWithIdCarriesVenueAndOrganizerReferences(): void
    {
        $event = new Event(
            id: new EventId(0),
            uid: 'uid',
            name: 'Placed Event',
            description: '',
            location: '',
            start: new \DateTimeImmutable(),
            duration: 0,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            venueId: new \WebCalendar\Core\Domain\ValueObject\VenueId(5),
            organizerId: new \WebCalendar\Core\Domain\ValueObject\OrganizerId(3),
        );

        $saved = $event->withId(new EventId(9));

        $this->assertSame(5, $saved->venueId()?->value());
        $this->assertSame(3, $saved->organizerId()?->value());
    }
}
