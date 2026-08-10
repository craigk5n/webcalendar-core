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
use Icalendar\Property\GenericProperty;

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

    // ── TZID handling on import (regression: issue #1) ────────────
    //
    // DTSTART;TZID=Europe/London:20260604T190000 must be parsed in
    // Europe/London (BST = UTC+1 in June), i.e. 18:00 UTC. The process
    // default timezone is pinned to UTC to mirror the WordPress runtime
    // where the bug was observed (zoned local times silently reinterpreted
    // against UTC, yielding a +1h offset).

    public function testFromVEventHonorsDtStartTzid(): void
    {
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $vevent = new VEvent();
            $vevent->setUid('tz-uid');
            $vevent->setSummary('London Event');
            $vevent->setDtStart('20260604T190000');
            $vevent->getProperty('DTSTART')?->setParameter('TZID', 'Europe/London');
            $vevent->setDuration('PT1H');

            $event = $this->mapper->fromVEvent($vevent, 'creator-login');

            // 19:00 Europe/London (BST) == 18:00 UTC.
            $utc = $event->start()->setTimezone(new \DateTimeZone('UTC'));
            $this->assertSame(
                '2026-06-04 18:00:00',
                $utc->format('Y-m-d H:i:s'),
                'DTSTART TZID was dropped; zoned local time misread against UTC.'
            );
        } finally {
            date_default_timezone_set($previousTz);
        }
    }

    public function testFromVEventFloatingTimeUsesProcessTimezone(): void
    {
        // Floating (no TZID, no Z): wall-clock is preserved as-is, interpreted
        // against the process default timezone. With default = UTC the instant
        // is the literal 09:00 UTC.
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $vevent = new VEvent();
            $vevent->setUid('float-uid');
            $vevent->setSummary('Floating Event');
            $vevent->setDtStart('20260604T090000');
            $vevent->setDuration('PT1H');

            $event = $this->mapper->fromVEvent($vevent, 'creator-login');

            $utc = $event->start()->setTimezone(new \DateTimeZone('UTC'));
            $this->assertSame('2026-06-04 09:00:00', $utc->format('Y-m-d H:i:s'));
        } finally {
            date_default_timezone_set($previousTz);
        }
    }

    public function testFromVEventUtcZuluTime(): void
    {
        // Explicit UTC (trailing Z): TZID, if any, is ignored by the parser and
        // the instant is exactly the stated UTC time.
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $vevent = new VEvent();
            $vevent->setUid('zulu-uid');
            $vevent->setSummary('Zulu Event');
            $vevent->setDtStart('20260604T180000Z');
            $vevent->setDuration('PT1H');

            $event = $this->mapper->fromVEvent($vevent, 'creator-login');

            $utc = $event->start()->setTimezone(new \DateTimeZone('UTC'));
            $this->assertSame('2026-06-04 18:00:00', $utc->format('Y-m-d H:i:s'));
        } finally {
            date_default_timezone_set($previousTz);
        }
    }

    public function testFromVEventDtEndTzidSpanningDstBoundary(): void
    {
        // Europe/London "spring forward" 2026: clocks jump 01:00→02:00 GMT on
        // Sun 29 Mar. An event 00:30→02:30 local crosses the gap. Honoring TZID
        // on both DTSTART and DTEND yields the correct elapsed wall time:
        //   00:30 BST-pre == 00:30 UTC, 02:30 BST == 01:30 UTC → 60 real minutes.
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $vevent = new VEvent();
            $vevent->setUid('dst-uid');
            $vevent->setSummary('DST Boundary Event');
            $vevent->setDtStart('20260329T003000');
            $vevent->getProperty('DTSTART')?->setParameter('TZID', 'Europe/London');
            $vevent->setDtEnd('20260329T023000');
            $vevent->getProperty('DTEND')?->setParameter('TZID', 'Europe/London');

            $event = $this->mapper->fromVEvent($vevent, 'creator-login');

            // 00:30 UTC start.
            $utcStart = $event->start()->setTimezone(new \DateTimeZone('UTC'));
            $this->assertSame('2026-03-29 00:30:00', $utcStart->format('Y-m-d H:i:s'));
            // Real elapsed time across the DST gap is 60 minutes, not 120.
            $this->assertSame(60, $event->duration());
        } finally {
            date_default_timezone_set($previousTz);
        }
    }

    // ── Calendar-timezone conversion on import (regression: issue #2) ──
    //
    // Distinct from #1. A UTC ("Z") date-time parses to the correct absolute
    // instant, but WebCalendar's storage model is timezone-naive: it persists
    // the start's wall-clock (Ymd/His). When the mapper is given the calendar's
    // display timezone, absolute instants (Z or TZID) must be rebased to that
    // zone BEFORE the wall-clock is read, so a Europe/London calendar stores
    // 09:00 (not 08:00) for an 08:00Z summer event. The process default TZ is
    // pinned to UTC to mirror the WordPress runtime where the bug was found.

    public function testFromVEventUtcConvertedToTargetTimezoneSummer(): void
    {
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $mapper = new EventMapper(new \DateTimeZone('Europe/London'));

            $vevent = new VEvent();
            $vevent->setUid('summer-1');
            $vevent->setSummary('Summer 9am meeting');
            $vevent->setDtStart('20260604T080000Z');
            $vevent->setDtEnd('20260604T150000Z');

            $event = $mapper->fromVEvent($vevent, 'admin');

            // 08:00Z is 09:00 Europe/London during BST. The stored wall-clock
            // (what PdoEventRepository persists) must read 09:00 local.
            $this->assertSame(
                '2026-06-04 09:00',
                $event->start()->format('Y-m-d H:i'),
                'UTC Z time was not rebased to the calendar timezone (off-by-one-hour).'
            );
            // Absolute instant is unchanged by the rebase.
            $this->assertSame(
                '2026-06-04 08:00',
                $event->start()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i')
            );
        } finally {
            date_default_timezone_set($previousTz);
        }
    }

    public function testFromVEventUtcConvertedToTargetTimezoneWinter(): void
    {
        // Winter: Europe/London == UTC (GMT). Rebasing must be a no-op, never a
        // double shift — 17:00Z stays 17:00 local.
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $mapper = new EventMapper(new \DateTimeZone('Europe/London'));

            $vevent = new VEvent();
            $vevent->setUid('winter-1');
            $vevent->setSummary('Winter 5pm meeting');
            $vevent->setDtStart('20261202T170000Z');
            $vevent->setDtEnd('20261202T180000Z');

            $event = $mapper->fromVEvent($vevent, 'admin');

            $this->assertSame(
                '2026-12-02 17:00',
                $event->start()->format('Y-m-d H:i')
            );
        } finally {
            date_default_timezone_set($previousTz);
        }
    }

    public function testFromVEventUtcConversionShiftsNearMidnightToNextDay(): void
    {
        // A near-midnight UTC instant lands on the adjacent local day once
        // rebased: 23:30Z on Thu 4 Jun is 00:30 Fri 5 Jun in Europe/London
        // (BST). This is the "missing Thursday events" symptom in the issue.
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $mapper = new EventMapper(new \DateTimeZone('Europe/London'));

            $vevent = new VEvent();
            $vevent->setUid('midnight-1');
            $vevent->setSummary('Late Thursday meeting');
            $vevent->setDtStart('20260604T233000Z');
            $vevent->setDtEnd('20260605T003000Z');

            $event = $mapper->fromVEvent($vevent, 'admin');

            $this->assertSame(
                '2026-06-05 00:30',
                $event->start()->format('Y-m-d H:i'),
                'Near-midnight UTC time did not roll to the correct local day.'
            );
        } finally {
            date_default_timezone_set($previousTz);
        }
    }

    public function testFromVEventFloatingTimeNotConvertedWithTargetTimezone(): void
    {
        // Floating times (no Z, no TZID) have no absolute meaning; they must be
        // left as literal wall-clock even when a target timezone is configured.
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $mapper = new EventMapper(new \DateTimeZone('Europe/London'));

            $vevent = new VEvent();
            $vevent->setUid('float-1');
            $vevent->setSummary('Floating meeting');
            $vevent->setDtStart('20260604T090000');
            $vevent->setDuration('PT1H');

            $event = $mapper->fromVEvent($vevent, 'admin');

            $this->assertSame(
                '2026-06-04 09:00',
                $event->start()->format('Y-m-d H:i'),
                'Floating wall-clock must not be shifted by the target timezone.'
            );
        } finally {
            date_default_timezone_set($previousTz);
        }
    }

    public function testFromVEventZonedTimeRebasedToDifferentTargetTimezone(): void
    {
        // A TZID-zoned time in a zone other than the calendar's: 09:00 New York
        // (EDT = UTC-4 in June) is 14:00 Europe/London (BST). The stored
        // wall-clock must be expressed in the calendar timezone.
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $mapper = new EventMapper(new \DateTimeZone('Europe/London'));

            $vevent = new VEvent();
            $vevent->setUid('zoned-1');
            $vevent->setSummary('NY morning call');
            $vevent->setDtStart('20260604T090000');
            $vevent->getProperty('DTSTART')?->setParameter('TZID', 'America/New_York');
            $vevent->setDuration('PT1H');

            $event = $mapper->fromVEvent($vevent, 'admin');

            $this->assertSame(
                '2026-06-04 14:00',
                $event->start()->format('Y-m-d H:i')
            );
        } finally {
            date_default_timezone_set($previousTz);
        }
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

    // ── HTML description triple output on export ──────────────────

    public function testToVEventHtmlTripleOutput(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $event = new Event(
            id: new EventId(1),
            uid: 'html-uid',
            name: 'HTML Event',
            description: '<p>Hello <strong>World</strong></p>',
            location: 'Online',
            start: $start,
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $vevent = $this->mapper->toVEvent($event);

        // 1. STYLED-DESCRIPTION with FMTTYPE=text/html
        $styledProp = $vevent->getProperty('STYLED-DESCRIPTION');
        $this->assertNotNull($styledProp, 'STYLED-DESCRIPTION should be present for HTML');
        $this->assertSame('<p>Hello <strong>World</strong></p>', $styledProp->getValue()->getRawValue());
        $this->assertSame('text/html', $styledProp->getParameter('FMTTYPE'));
        $this->assertSame('TEXT', $styledProp->getParameter('VALUE'));

        // 2. X-ALT-DESC with FMTTYPE=text/html
        $xAltProp = $vevent->getProperty('X-ALT-DESC');
        $this->assertNotNull($xAltProp, 'X-ALT-DESC should be present for HTML');
        $this->assertSame('<p>Hello <strong>World</strong></p>', $xAltProp->getValue()->getRawValue());
        $this->assertSame('text/html', $xAltProp->getParameter('FMTTYPE'));

        // 3. DESCRIPTION with DERIVED=TRUE (plain-text fallback)
        $descProp = $vevent->getProperty('DESCRIPTION');
        $this->assertNotNull($descProp, 'DESCRIPTION (derived) should be present');
        $this->assertSame('TRUE', $descProp->getParameter('DERIVED'));
        $this->assertStringNotContainsString('<', $descProp->getValue()->getRawValue());
        $this->assertStringContainsString('Hello', $descProp->getValue()->getRawValue());
        $this->assertStringContainsString('World', $descProp->getValue()->getRawValue());
    }

    public function testToVEventPlainSingleOutput(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $event = new Event(
            id: new EventId(1),
            uid: 'plain-uid',
            name: 'Plain Event',
            description: 'Just plain text',
            location: 'Online',
            start: $start,
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $vevent = $this->mapper->toVEvent($event);

        // Only DESCRIPTION, no STYLED-DESCRIPTION or X-ALT-DESC
        $this->assertSame('Just plain text', $vevent->getDescription());
        $this->assertNull($vevent->getProperty('STYLED-DESCRIPTION'));
        $this->assertNull($vevent->getProperty('X-ALT-DESC'));
    }

    // ── HTML description import priority chain ────────────────────

    public function testFromVEventPrefersStyledDescription(): void
    {
        $vevent = new VEvent();
        $vevent->setUid('import-styled');
        $vevent->setSummary('Styled Import');
        $vevent->setDtStart('20260211T100000');
        $vevent->setDuration('PT1H');

        // Add STYLED-DESCRIPTION (highest priority)
        $styledProp = GenericProperty::create('STYLED-DESCRIPTION', '<p>Styled HTML</p>');
        $styledProp->setParameter('VALUE', 'TEXT');
        $styledProp->setParameter('FMTTYPE', 'text/html');
        $vevent->addProperty($styledProp);

        // Also add X-ALT-DESC and DESCRIPTION
        $xAltProp = GenericProperty::create('X-ALT-DESC', '<p>Alt HTML</p>');
        $xAltProp->setParameter('FMTTYPE', 'text/html');
        $vevent->addProperty($xAltProp);
        $vevent->setDescription('Plain text');

        $event = $this->mapper->fromVEvent($vevent, 'admin');

        $this->assertSame('<p>Styled HTML</p>', $event->description());
    }

    public function testFromVEventFallsBackToXAltDesc(): void
    {
        $vevent = new VEvent();
        $vevent->setUid('import-xalt');
        $vevent->setSummary('XAlt Import');
        $vevent->setDtStart('20260211T100000');
        $vevent->setDuration('PT1H');

        // No STYLED-DESCRIPTION; add X-ALT-DESC
        $xAltProp = GenericProperty::create('X-ALT-DESC', '<p>Outlook HTML</p>');
        $xAltProp->setParameter('FMTTYPE', 'text/html');
        $vevent->addProperty($xAltProp);
        $vevent->setDescription('Plain text');

        $event = $this->mapper->fromVEvent($vevent, 'admin');

        $this->assertSame('<p>Outlook HTML</p>', $event->description());
    }

    public function testFromVEventFallsBackToDescription(): void
    {
        $vevent = new VEvent();
        $vevent->setUid('import-plain');
        $vevent->setSummary('Plain Import');
        $vevent->setDtStart('20260211T100000');
        $vevent->setDuration('PT1H');

        // Only plain DESCRIPTION
        $vevent->setDescription('Just plain text');

        $event = $this->mapper->fromVEvent($vevent, 'admin');

        $this->assertSame('Just plain text', $event->description());
    }

    // ── Epic 22.3: venue / organizer mapping ────────────────────────

    private function placedEvent(): Event
    {
        return new Event(
            id: new EventId(1),
            uid: 'uid-placed',
            name: 'Placed Event',
            description: '',
            location: 'Old Location String',
            start: new \DateTimeImmutable('2026-09-10 10:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );
    }

    public function testToVEventWithVenueWinsOverLocationAndSetsGeo(): void
    {
        $venue = new \WebCalendar\Core\Domain\Entity\Venue(
            id: new \WebCalendar\Core\Domain\ValueObject\VenueId(5),
            name: 'Community Hall',
            latitude: 38.4496,
            longitude: -78.8689,
        );

        $vevent = $this->mapper->toVEvent($this->placedEvent(), $venue);

        $this->assertSame('Community Hall', $vevent->getLocation());
        $geo = $vevent->getGeo();
        $this->assertNotNull($geo);
        $this->assertEqualsWithDelta(38.4496, $geo['latitude'], 0.0001);
        $this->assertEqualsWithDelta(-78.8689, $geo['longitude'], 0.0001);
    }

    public function testToVEventWithoutVenueKeepsTheLocationString(): void
    {
        $vevent = $this->mapper->toVEvent($this->placedEvent());

        $this->assertSame('Old Location String', $vevent->getLocation());
    }

    public function testToVEventWithOrganizerEmitsOrganizerProperty(): void
    {
        $organizer = new \WebCalendar\Core\Domain\Entity\Organizer(
            id: new \WebCalendar\Core\Domain\ValueObject\OrganizerId(3),
            name: 'Alice Organizer',
            email: 'alice@example.com',
        );

        $vevent = $this->mapper->toVEvent($this->placedEvent(), null, $organizer);

        $prop = $vevent->getProperty('ORGANIZER');
        $this->assertNotNull($prop);
        $this->assertSame('mailto:alice@example.com', $prop->getValue()->getRawValue());
        $this->assertSame('Alice Organizer', $prop->getParameter('CN'));
    }

    public function testToVEventOmitsOrganizerWithoutEmail(): void
    {
        // RFC 5545 ORGANIZER values must be cal-addresses; a name alone
        // cannot be expressed, so the property is omitted.
        $organizer = new \WebCalendar\Core\Domain\Entity\Organizer(
            id: new \WebCalendar\Core\Domain\ValueObject\OrganizerId(3),
            name: 'Bob NoContact',
        );

        $vevent = $this->mapper->toVEvent($this->placedEvent(), null, $organizer);

        $this->assertNull($vevent->getProperty('ORGANIZER'));
    }

    public function testExtractOrganizerReadsCnAndMailto(): void
    {
        $vevent = new VEvent();
        $prop = GenericProperty::create('ORGANIZER', 'mailto:alice@example.com');
        $prop->setParameter('CN', 'Alice Organizer');
        $vevent->addProperty($prop);

        $extracted = $this->mapper->extractOrganizer($vevent);

        $this->assertNotNull($extracted);
        $this->assertSame('Alice Organizer', $extracted['name']);
        $this->assertSame('alice@example.com', $extracted['email']);
    }

    public function testExtractOrganizerWithoutCnFallsBackToTheAddress(): void
    {
        $vevent = new VEvent();
        $vevent->addProperty(GenericProperty::create('ORGANIZER', 'mailto:bob@example.com'));

        $extracted = $this->mapper->extractOrganizer($vevent);

        $this->assertNotNull($extracted);
        $this->assertSame('bob@example.com', $extracted['name']);
        $this->assertSame('bob@example.com', $extracted['email']);
    }

    public function testExtractOrganizerIsNullWhenAbsent(): void
    {
        $this->assertNull($this->mapper->extractOrganizer(new VEvent()));
    }
}
