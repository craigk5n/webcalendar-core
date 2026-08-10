<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Infrastructure\Import;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Infrastructure\Import\EventbriteMapper;
use WebCalendar\Core\Infrastructure\Import\MeetupMapper;

/**
 * Epic 27 — connector payload mapping. Fixtures follow the documented
 * Eventbrite v3 / Meetup API shapes; validating against freshly
 * captured live payloads is tracked in STATUS.md.
 */
final class ExternalMapperTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $json = file_get_contents(__DIR__ . '/../../../fixtures/import/' . $name);
        $this->assertNotFalse($json);
        /** @var array<string, mixed> */
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testEventbriteEventMapsCompletely(): void
    {
        $mapped = (new EventbriteMapper('example.test'))->map($this->fixture('eventbrite-event.json'), 'importer');

        $event = $mapped->event;
        $this->assertSame('eventbrite-987654321012@example.test', $event->uid());
        $this->assertSame('WC-FIX Makers Fair', $event->name());
        $this->assertSame('A day of making things.', $event->description());
        $this->assertSame('2026-10-03 10:00', $event->start()->format('Y-m-d H:i'), 'local wall time in the event timezone');
        $this->assertSame(360, $event->duration());
        $this->assertSame('importer', $event->createdBy());
        $this->assertNull($event->conferenceUrl(), 'not an online event');

        $venue = $mapped->venue;
        $this->assertNotNull($venue);
        $this->assertSame('WC-FIX Fairgrounds', $venue->name());
        $this->assertSame('Harrisonburg', $venue->city());
        $this->assertSame('VA', $venue->state());
        $this->assertEqualsWithDelta(38.4496, $venue->latitude(), 0.0001);
        $this->assertSame('WC-FIX Fairgrounds', $event->location(), 'venue name fills the legacy location string');

        $this->assertSame('WC-FIX Makers Guild', $mapped->organizer?->name());
        $this->assertSame('https://img.evbuc.com/wc-fix-logo.jpg', $mapped->imageUrl);
        $this->assertSame(0, $venue->id()->value(), 'venue arrives unsaved for matchOrCreate');
    }

    public function testEventbriteOnlineEventMapsConferenceFields(): void
    {
        $payload = $this->fixture('eventbrite-event.json');
        $payload['online_event'] = true;
        $payload['venue'] = null;

        $mapped = (new EventbriteMapper('example.test'))->map($payload, 'importer');

        $this->assertNull($mapped->venue);
        $this->assertSame(
            'https://www.eventbrite.com/e/wc-fix-makers-fair-tickets-987654321012',
            $mapped->event->conferenceUrl()
        );
    }

    public function testEventbriteRejectsPayloadWithoutId(): void
    {
        $payload = $this->fixture('eventbrite-event.json');
        unset($payload['id']);

        $this->expectException(\InvalidArgumentException::class);
        (new EventbriteMapper('example.test'))->map($payload, 'importer');
    }

    public function testEventbriteIncompleteCoordinatesAreDroppedWithAWarning(): void
    {
        $payload = $this->fixture('eventbrite-event.json');
        assert(is_array($payload['venue']) && is_array($payload['venue']['address']));
        unset($payload['venue']['address']['longitude']);

        $mapped = (new EventbriteMapper('example.test'))->map($payload, 'importer');

        $this->assertNotNull($mapped->venue);
        $this->assertNull($mapped->venue->latitude());
        $this->assertNotSame([], $mapped->warnings);
    }

    public function testMeetupOnlineEventMapsCompletely(): void
    {
        $mapped = (new MeetupMapper('example.test'))->map($this->fixture('meetup-event.json'), 'importer');

        $event = $mapped->event;
        $this->assertSame('meetup-300123456@example.test', $event->uid());
        $this->assertSame('WC-FIX PHP User Group', $event->name());
        $this->assertSame('2026-10-07 18:30', $event->start()->format('Y-m-d H:i'));
        $this->assertSame(120, $event->duration());
        $this->assertSame('https://zoom.example.com/j/9955', $event->conferenceUrl());
        $this->assertSame('Zoom', $event->conferenceLabel());
        $this->assertNull($mapped->venue, 'online event has no physical venue');
        $organizer = $mapped->organizer;
        $this->assertNotNull($organizer);
        $this->assertSame('WC-FIX PHP', $organizer->name());
        $this->assertSame('https://www.meetup.com/wc-fix-php/', $organizer->url());
        $this->assertSame('https://secure.meetupstatic.com/photos/wc-fix.jpeg', $mapped->imageUrl);
    }

    public function testMeetupPhysicalVenueMaps(): void
    {
        $payload = $this->fixture('meetup-event.json');
        $payload['onlineVenue'] = null;
        $payload['venue'] = [
            'name' => 'WC-FIX Coworking',
            'address' => '12 Main St',
            'city' => 'Dayton',
            'state' => 'VA',
            'postalCode' => '22821',
            'country' => 'us',
            'lat' => 38.41,
            'lng' => -78.94,
        ];

        $mapped = (new MeetupMapper('example.test'))->map($payload, 'importer');

        $this->assertNull($mapped->event->conferenceUrl());
        $this->assertNotNull($mapped->venue);
        $this->assertSame('WC-FIX Coworking', $mapped->venue->name());
        $this->assertSame('Dayton', $mapped->venue->city());
        $this->assertEqualsWithDelta(-78.94, $mapped->venue->longitude(), 0.0001);
    }

    public function testMeetupRejectsPayloadWithoutStart(): void
    {
        $payload = $this->fixture('meetup-event.json');
        unset($payload['dateTime']);

        $this->expectException(\InvalidArgumentException::class);
        (new MeetupMapper('example.test'))->map($payload, 'importer');
    }
}
