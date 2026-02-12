<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Infrastructure\ICal;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Infrastructure\ICal\EventMapper;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use Icalendar\Component\VEvent;

final class EventMapperTest extends TestCase
{
    private EventMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new EventMapper();
    }

    public function testFromVEvent(): void
    {
        $vevent = new VEvent();
        $vevent->setUid('test-uid');
        $vevent->setSummary('Test Event');
        $vevent->setDescription('This is a test');
        $vevent->setLocation('Online');
        $vevent->setDtStart('20260211T100000');
        $vevent->setDuration('PT1H');

        $event = $this->mapper->fromVEvent($vevent, 'creator-login');

        $this->assertSame('test-uid', $event->uid());
        $this->assertSame('Test Event', $event->name());
        $this->assertSame('This is a test', $event->description());
        $this->assertSame('Online', $event->location());
        $this->assertSame('2026-02-11 10:00:00', $event->start()->format('Y-m-d H:i:s'));
        $this->assertSame(60, $event->duration());
        $this->assertSame('creator-login', $event->createdBy());
        $this->assertSame(EventType::EVENT, $event->type());
        $this->assertSame(AccessLevel::PUBLIC, $event->access());
    }

    public function testToVEvent(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $event = new Event(
            id: new EventId(1),
            uid: 'test-uid',
            name: 'Test Event',
            description: 'This is a test',
            location: 'Online',
            start: $start,
            duration: 60,
            createdBy: 'creator-login',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $vevent = $this->mapper->toVEvent($event);

        $this->assertSame('test-uid', $vevent->getUid());
        $this->assertSame('Test Event', $vevent->getSummary());
        $this->assertSame('This is a test', $vevent->getDescription());
        $this->assertSame('Online', $vevent->getLocation());
        $this->assertSame('20260211T100000', $vevent->getDtStart());
        $this->assertSame('PT60M', $vevent->getDuration());
    }
}
