<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\ICal;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\RecurrenceRule;
use Icalendar\Component\VEvent;
use Icalendar\Parser\ValueParser\DateParser;
use Icalendar\Parser\ValueParser\DateTimeParser;
use Icalendar\Parser\ValueParser\DurationParser;
use Icalendar\Property\GenericProperty;

/**
 * Mapper for translating between Domain Event entities and iCalendar VEvent components.
 */
final readonly class EventMapper
{
    private DateParser $dateParser;
    private DateTimeParser $dateTimeParser;
    private DurationParser $durationParser;

    public function __construct()
    {
        $this->dateParser = new DateParser();
        $this->dateTimeParser = new DateTimeParser();
        $this->durationParser = new DurationParser();
    }

    /**
     * Maps a VEvent component to a Domain Event entity.
     * 
     * @param VEvent $vevent The iCalendar VEvent component.
     * @param string $createdBy The login of the user importing the event.
     * @return Event
     */
    public function fromVEvent(VEvent $vevent, string $createdBy): Event
    {
        $uid = $vevent->getUid() ?? '';
        $name = $vevent->getSummary() ?? 'Untitled Event';
        $description = $this->extractDescription($vevent);
        $location = $vevent->getLocation() ?? '';

        // Detect all-day event: DTSTART with VALUE=DATE (no time component)
        $dtStartProp = $vevent->getProperty('DTSTART');
        $valueParam = $dtStartProp?->getParameter('VALUE');
        $allDay = ($valueParam === 'DATE');

        $startStr = $vevent->getDtStart();
        if ($allDay && $startStr !== null) {
            // Pure date format (YYYYMMDD) — use DateParser
            $start = $this->dateParser->parse($startStr);
        } else {
            $start = $startStr !== null
                ? $this->dateTimeParser->parse($startStr)
                : new \DateTimeImmutable();
        }

        $durationMinutes = 0;
        if ($allDay) {
            // All-day: compute duration from DTEND date difference, default 1 day
            $endStr = $vevent->getDtEnd();
            if ($endStr !== null) {
                $end = $this->dateParser->parse($endStr);
                $days = (int) (($end->getTimestamp() - $start->getTimestamp()) / 86400);
                $durationMinutes = max($days, 1) * 1440;
            } else {
                $durationMinutes = 1440; // Default: 1-day all-day event
            }
        } else {
            $durationStr = $vevent->getDuration();
            if ($durationStr !== null) {
                $interval = $this->durationParser->parse($durationStr);
                $durationMinutes = $this->intervalToMinutes($interval);
            } else {
                $endStr = $vevent->getDtEnd();
                if ($endStr !== null) {
                    $end = $this->dateTimeParser->parse($endStr);
                    $durationMinutes = (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);
                }
            }
        }

        $recurrence = new Recurrence();
        $rruleStr = $vevent->getRrule();
        if ($rruleStr !== null) {
            $recurrence = new Recurrence(rule: new RecurrenceRule($rruleStr));
        }

        // Map CLASS property to AccessLevel (RFC 5545 default is PUBLIC)
        $classValue = $vevent->getProperty('CLASS')?->getValue()->getRawValue();
        $access = match (strtoupper($classValue ?? '')) {
            'PRIVATE' => AccessLevel::PRIVATE,
            'CONFIDENTIAL' => AccessLevel::CONFIDENTIAL,
            default => AccessLevel::PUBLIC,
        };

        return new Event(
            id: new EventId(0),
            uid: $uid,
            name: $name,
            description: $description,
            location: $location,
            start: $start,
            duration: $durationMinutes,
            createdBy: $createdBy,
            type: EventType::EVENT,
            access: $access,
            recurrence: $recurrence,
            sequence: (int) ($vevent->getProperty('SEQUENCE')?->getValue()->getRawValue() ?? 0),
            status: $vevent->getProperty('STATUS')?->getValue()->getRawValue(),
            allDay: $allDay,
        );
    }

    /**
     * Maps a Domain Event entity to a VEvent component.
     * 
     * @param Event $event The Domain Event entity.
     * @return VEvent
     */
    public function toVEvent(Event $event): VEvent
    {
        $vevent = new VEvent();
        $vevent->setUid($event->uid());
        $vevent->setSummary($event->name());
        $this->setDescription($vevent, $event->description());
        $vevent->setLocation($event->location());

        if ($event->isAllDay()) {
            // RFC 5545: all-day events use VALUE=DATE format
            $vevent->setDtStart($event->start()->format('Ymd'));
            $dtStartProp = $vevent->getProperty('DTSTART');
            $dtStartProp?->setParameter('VALUE', 'DATE');

            // DTEND is exclusive (next day after last day)
            $days = max((int) ($event->duration() / 1440), 1);
            $endDate = $event->start()->modify("+{$days} days");
            $vevent->setDtEnd($endDate->format('Ymd'));
            $dtEndProp = $vevent->getProperty('DTEND');
            $dtEndProp?->setParameter('VALUE', 'DATE');
        } else {
            $vevent->setDtStart($event->start()->format('Ymd\THis'));
            $vevent->setDuration(sprintf('PT%dM', $event->duration()));
        }

        if ($event->recurrence()->rule() !== null) {
            $vevent->setRrule($event->recurrence()->rule()->toString());
        }

        // Map AccessLevel to CLASS property
        $classValue = match ($event->access()) {
            AccessLevel::PRIVATE => 'PRIVATE',
            AccessLevel::CONFIDENTIAL => 'CONFIDENTIAL',
            AccessLevel::PUBLIC => 'PUBLIC',
        };
        $vevent->addProperty(GenericProperty::create('CLASS', $classValue));

        return $vevent;
    }

    /**
     * Extracts category names from a VEvent's CATEGORIES property.
     *
     * @return string[]
     */
    public function extractCategoryNames(VEvent $vevent): array
    {
        return $vevent->getCategories();
    }

    /**
     * Adds category names to a VEvent as a CATEGORIES property.
     *
     * @param string[] $names
     */
    public function addCategoryNames(VEvent $vevent, array $names): void
    {
        if (!empty($names)) {
            $vevent->setCategories(...$names);
        }
    }

    /**
     * Extract the best available description from a VEvent.
     *
     * Priority: STYLED-DESCRIPTION (RFC 9073) → X-ALT-DESC (Outlook) → DESCRIPTION (plain).
     */
    private function extractDescription(VEvent $vevent): string
    {
        // 1. RFC 9073 STYLED-DESCRIPTION
        $styledProp = $vevent->getProperty('STYLED-DESCRIPTION');
        if ($styledProp !== null) {
            return $styledProp->getValue()->getRawValue();
        }

        // 2. Microsoft X-ALT-DESC with FMTTYPE=text/html
        $xAltProp = $vevent->getProperty('X-ALT-DESC');
        if ($xAltProp !== null) {
            $fmtType = $xAltProp->getParameter('FMTTYPE');
            if ($fmtType !== null && stripos($fmtType, 'text/html') !== false) {
                return $xAltProp->getValue()->getRawValue();
            }
        }

        // 3. Plain DESCRIPTION fallback
        return $vevent->getDescription() ?? '';
    }

    /**
     * Set description on a VEvent, using triple output for HTML content.
     *
     * HTML descriptions produce:
     * 1. STYLED-DESCRIPTION;VALUE=TEXT;FMTTYPE=text/html (RFC 9073)
     * 2. X-ALT-DESC;FMTTYPE=text/html (Outlook/Thunderbird compat)
     * 3. DESCRIPTION;DERIVED=TRUE (plain-text fallback)
     *
     * Plain-text descriptions produce a single DESCRIPTION property.
     */
    private function setDescription(VEvent $vevent, string $description): void
    {
        if ($description === '') {
            $vevent->setDescription('');
            return;
        }

        $isHtml = $description !== strip_tags($description);

        if (!$isHtml) {
            $vevent->setDescription($description);
            return;
        }

        // 1. STYLED-DESCRIPTION (RFC 9073)
        $styledProp = GenericProperty::create('STYLED-DESCRIPTION', $description);
        $styledProp->setParameter('VALUE', 'TEXT');
        $styledProp->setParameter('FMTTYPE', 'text/html');
        $vevent->addProperty($styledProp);

        // 2. X-ALT-DESC (Outlook/Thunderbird)
        $xAltProp = GenericProperty::create('X-ALT-DESC', $description);
        $xAltProp->setParameter('FMTTYPE', 'text/html');
        $vevent->addProperty($xAltProp);

        // 3. Plain-text fallback with DERIVED=TRUE
        $plainText = $this->htmlToPlainText($description);
        $plainProp = GenericProperty::create('DESCRIPTION', $plainText);
        $plainProp->setParameter('DERIVED', 'TRUE');
        $vevent->addProperty($plainProp);
    }

    /**
     * Convert HTML to plain text for the DESCRIPTION fallback.
     */
    private function htmlToPlainText(string $html): string
    {
        // Convert <br> variants to newlines
        $text = (string) preg_replace('/<br\s*\/?>/i', "\n", $html);
        // Convert </p> to double newline
        $text = (string) preg_replace('/<\/p>/i', "\n\n", $text);
        // Convert </li> to newline
        $text = (string) preg_replace('/<\/li>/i', "\n", $text);
        // Strip remaining tags
        $text = strip_tags($text);
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Normalize whitespace: collapse multiple blank lines
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    private function intervalToMinutes(\DateInterval $interval): int
    {
        return (int) ($interval->days * 24 * 60) +
               (int) ($interval->h * 60) +
               (int) ($interval->i);
    }
}
