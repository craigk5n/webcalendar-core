<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\ICal;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\ExDate;
use WebCalendar\Core\Domain\ValueObject\RDate;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\RecurrenceRule;
use Icalendar\Component\VEvent;
use Icalendar\Parser\ValueParser\DateParser;
use Icalendar\Parser\ValueParser\DateTimeParser;
use Icalendar\Parser\ValueParser\DurationParser;
use Icalendar\Property\GenericProperty;
use Icalendar\Property\PropertyInterface;

/**
 * Mapper for translating between Domain Event entities and iCalendar VEvent components.
 */
final class EventMapper
{
    private readonly DateParser $dateParser;
    private readonly DateTimeParser $dateTimeParser;
    private readonly DurationParser $durationParser;

    /**
     * @param \DateTimeZone|null $targetTimezone The calendar's display timezone.
     *   When provided, absolute date-times (UTC "Z" or zoned "TZID") are rebased
     *   to this zone before their wall-clock is read for the timezone-naive
     *   WebCalendar store, so they land on the correct local time and day.
     *   Floating times (no Z, no TZID) are never shifted. When null, no
     *   conversion is performed (legacy behavior).
     */
    public function __construct(
        private readonly ?\DateTimeZone $targetTimezone = null,
    ) {
        $this->dateParser = new DateParser();
        $this->dateTimeParser = new DateTimeParser();
        $this->durationParser = new DurationParser();
    }

    /**
     * SUMMARY and LOCATION as plain text.
     *
     * Both are text in RFC 5545, and every other way an event gets a title
     * strips tags, so a feed's markup is dropped here rather than trusted to
     * be escaped at every place a title is printed. Script and style bodies
     * go with their tags, as in WordPress's wp_strip_all_tags().
     */
    private static function plainText(string $value): string
    {
        $value = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $value);

        return trim(strip_tags($value));
    }

    /**
     * Maps a VEvent component to a Domain Event entity.
     */
    public function fromVEvent(VEvent $vevent, string $createdBy): Event
    {
        $uid = $vevent->getUid() ?? '';
        $name = self::plainText($vevent->getSummary() ?? '');
        if ($name === '') {
            $name = 'Untitled Event';
        }
        $description = $this->extractDescription($vevent);
        $location = self::plainText($vevent->getLocation() ?? '');

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
                ? $this->dateTimeParser->parse($startStr, $this->tzidParams($dtStartProp))
                : new \DateTimeImmutable();
            // Rebase absolute (Z / TZID) instants to the calendar timezone so the
            // timezone-naive store keeps the correct local wall-clock and day.
            $start = $this->rebaseToTarget($start, $startStr, $dtStartProp);
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
                    $dtEndProp = $vevent->getProperty('DTEND');
                    $end = $this->dateTimeParser->parse($endStr, $this->tzidParams($dtEndProp));
                    $durationMinutes = (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);
                }
            }
        }

        $rruleStr = $vevent->getRrule();
        $recurrence = new Recurrence(
            rule: $rruleStr !== null ? new RecurrenceRule($rruleStr) : null,
            exDate: new ExDate($this->readDateList($vevent, 'EXDATE')),
            rDate: new RDate($this->readDateList($vevent, 'RDATE')),
        );

        // Map CLASS property to AccessLevel (RFC 5545 default is PUBLIC)
        $classProp = $vevent->getProperty('CLASS');
        $classValue = $classProp !== null ? $classProp->getValue()->getRawValue() : '';
        $access = match (strtoupper($classValue)) {
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
            sequence: $this->extractIntProperty($vevent, 'SEQUENCE'),
            status: $this->extractStringProperty($vevent, 'STATUS'),
            allDay: $allDay,
            image: $vevent->getImage(),
            conferenceUrl: $vevent->getConference(),
            conferenceLabel: $vevent->getProperty('CONFERENCE')?->getParameter('LABEL'),
        );
    }

    /**
     * Maps a Domain Event entity to a VEvent component.
     */
    public function toVEvent(
        Event $event,
        ?\WebCalendar\Core\Domain\Entity\Venue $venue = null,
        ?\WebCalendar\Core\Domain\Entity\Organizer $organizer = null,
    ): VEvent {
        $vevent = new VEvent();
        $vevent->setUid($event->uid());
        $vevent->setSummary($event->name());
        $this->setDescription($vevent, $event->description());

        // Epic 22.3: a resolved venue wins over the legacy location string,
        // and its coordinates become GEO.
        $vevent->setLocation($venue !== null ? $venue->name() : $event->location());
        if ($venue !== null && $venue->hasCoordinates()) {
            /** @psalm-suppress PossiblyNullArgument -- hasCoordinates() guarantees both */
            $vevent->setGeo((float) $venue->latitude(), (float) $venue->longitude());
        }

        // RFC 7986 CONFERENCE — the virtual-meeting link (Epic 26).
        $conferenceUrl = $event->conferenceUrl();
        if ($conferenceUrl !== null) {
            $vevent->setConference($conferenceUrl);
            $conference     = $vevent->getProperty('CONFERENCE');
            $conference?->setParameter('VALUE', 'URI');
            $conferenceLabel = $event->conferenceLabel();
            if ($conferenceLabel !== null) {
                $conference?->setParameter('LABEL', $conferenceLabel);
            }
        }

        // RFC 5545 ORGANIZER values must be cal-addresses (mailto:); an
        // organizer without an email cannot be expressed and is omitted.
        if ($organizer !== null && $organizer->email() !== null) {
            $property = GenericProperty::create(
                'ORGANIZER',
                'mailto:' . $organizer->email()
            );
            $property->setParameter('CN', $organizer->name());
            $vevent->addProperty($property);
        }

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

        // setRrule() writes RECUR since php-icalendar-core 1.2.1; 1.2.0 wrote
        // TEXT, escaping the rule's semicolons into a rule no reader accepts.
        $rule = $event->recurrence()->rule();
        if ($rule !== null) {
            $vevent->setRrule($rule->toString());
        }
        $this->writeDateList($vevent, 'EXDATE', $event->recurrence()->exDate()->dates(), $event);
        $this->writeDateList($vevent, 'RDATE', $event->recurrence()->rDate()->dates(), $event);

        // Map AccessLevel to CLASS property
        $classValue = match ($event->access()) {
            AccessLevel::PRIVATE => 'PRIVATE',
            AccessLevel::CONFIDENTIAL => 'CONFIDENTIAL',
            AccessLevel::PUBLIC => 'PUBLIC',
        };
        $vevent->addProperty(GenericProperty::create('CLASS', $classValue));

        // RFC 7986 IMAGE property
        $image = $event->image();
        if ($image !== null && $image !== '') {
            $vevent->setImage($image);
        }

        return $vevent;
    }

    /**
     * The ORGANIZER property's name and email, for match-or-create on
     * import (Epic 22.3). CN falls back to the mail address so an
     * organizer without a display name still gets a usable entity name.
     *
     * @return array{name: string, email: string}|null Null when absent or
     *         not a mailto: cal-address.
     */
    public function extractOrganizer(VEvent $vevent): ?array
    {
        $property = $vevent->getProperty('ORGANIZER');
        if ($property === null) {
            return null;
        }

        $value = $property->getValue()->getRawValue();
        if (stripos($value, 'mailto:') !== 0) {
            return null;
        }
        $email = substr($value, strlen('mailto:'));
        if ($email === '') {
            return null;
        }

        $cn = $property->getParameter('CN');

        return [
            'name' => $cn !== null && $cn !== '' ? $cn : $email,
            'email' => $email,
        ];
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
     * The property naming which of an event's CATEGORIES are tags.
     *
     * RFC 5545 has no tag concept — CATEGORIES is the only labelling
     * property, and RFC 7986 did not add one — so tags go out in CATEGORIES
     * like everything else (other calendars then show them all) and are
     * named a second time here. Import intersects the two to restore the
     * distinction, and anything that does not understand this property
     * simply sees one flat label list.
     */
    private const TAGS_PROPERTY = 'X-WEBCAL-TAGS';

    /**
     * Records which of the event's category names are tags.
     *
     * @param string[] $names
     */
    public function addTagNames(VEvent $vevent, array $names): void
    {
        if ([] === $names) {
            return;
        }

        $vevent->addProperty(
            GenericProperty::create(
                self::TAGS_PROPERTY,
                implode(',', array_map(
                    // The writer round-trips this value verbatim rather than
                    // treating it as a TEXT list, so the separator escaping
                    // is ours to do — and to undo on the way back in.
                    static fn (string $name): string => str_replace(['\\', ','], ['\\\\', '\\,'], $name),
                    $names
                ))
            )
        );
    }

    /**
     * Tag names recorded on a VEvent, or [] when the property is absent.
     *
     * @return list<string>
     */
    public function extractTagNames(VEvent $vevent): array
    {
        $property = $vevent->getProperty(self::TAGS_PROPERTY);
        if ($property === null) {
            return [];
        }

        $raw = $property->getValue()->getRawValue();

        // Split on commas that are not escaped, then unescape.
        $names = [];
        $current = '';
        $escaped = false;
        foreach (str_split($raw) as $char) {
            if ($escaped) {
                $current .= $char;
                $escaped = false;
                continue;
            }
            if ('\\' === $char) {
                $escaped = true;
                continue;
            }
            if (',' === $char) {
                $names[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $names[] = $current;

        $result = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ('' !== $name) {
                $result[] = $name;
            }
        }
        return $result;
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

    /**
     * The occurrence a VEVENT overrides, from its RECURRENCE-ID, on the
     * calendar's wall clock — or null when it is not an override.
     */
    public function extractRecurrenceId(VEvent $vevent): ?\DateTimeImmutable
    {
        $prop = $vevent->getProperty('RECURRENCE-ID');
        if ($prop === null) {
            return null;
        }

        return $this->parseDateValue($prop->getValue()->getRawValue(), $prop);
    }

    /**
     * Every date in every EXDATE or RDATE property (each may hold a
     * comma-separated list), on the calendar's wall clock.
     *
     * Storage keeps the day only, so an absolute time is rebased first: an
     * exclusion written in UTC must land on the same local day as the
     * occurrence it removes, which is rebased the same way from DTSTART.
     *
     * @return \DateTimeImmutable[]
     */
    private function readDateList(VEvent $vevent, string $name): array
    {
        $dates = [];
        foreach ($vevent->getAllProperties($name) as $prop) {
            foreach (explode(',', $prop->getValue()->getRawValue()) as $raw) {
                $raw = trim($raw);
                if ($raw === '') {
                    continue;
                }
                $dates[] = $this->parseDateValue($raw, $prop);
            }
        }
        return $dates;
    }

    private function parseDateValue(string $raw, PropertyInterface $prop): \DateTimeImmutable
    {
        if ($prop->getParameter('VALUE') === 'DATE' || strlen($raw) === 8) {
            return $this->dateParser->parse($raw);
        }

        $value = $this->dateTimeParser->parse($raw, $this->tzidParams($prop));
        return $this->rebaseToTarget($value, $raw, $prop);
    }

    /**
     * Emit one EXDATE or RDATE property per date.
     *
     * The store keeps only the day, so each date takes the series' own start
     * time — what the occurrence it names actually starts at. An all-day
     * series uses VALUE=DATE to match its DTSTART.
     *
     * @param \DateTimeImmutable[] $dates
     */
    private function writeDateList(VEvent $vevent, string $name, array $dates, Event $event): void
    {
        foreach ($dates as $date) {
            $value = $event->isAllDay()
                ? $date->format('Ymd')
                : $date->format('Ymd') . 'T' . $event->start()->format('His');
            $prop = GenericProperty::create($name, $value);
            if ($event->isAllDay()) {
                $prop->setParameter('VALUE', 'DATE');
            }
            $vevent->addProperty($prop);
        }
    }

    /**
     * Build the parameter array passed to DateTimeParser, forwarding the
     * property's TZID so zoned local times (e.g. DTSTART;TZID=Europe/London)
     * are parsed in their declared timezone rather than the process default.
     *
     * @return array<string, string>
     */
    private function tzidParams(?PropertyInterface $prop): array
    {
        $tzid = $prop?->getParameter('TZID');
        return ($tzid !== null && $tzid !== '') ? ['TZID' => $tzid] : [];
    }

    /**
     * Convert an absolute instant to the configured calendar timezone before its
     * wall-clock is stored. No-op when no target timezone is configured or when
     * the value is floating (no trailing "Z" and no TZID parameter): a floating
     * time has no absolute meaning and must be preserved as literal wall-clock.
     */
    private function rebaseToTarget(
        \DateTimeImmutable $value,
        ?string $rawValue,
        ?PropertyInterface $prop,
    ): \DateTimeImmutable {
        if ($this->targetTimezone === null || $rawValue === null) {
            return $value;
        }

        $isUtc = str_ends_with($rawValue, 'Z');
        $tzid = $prop?->getParameter('TZID');
        $isZoned = $tzid !== null && $tzid !== '';

        if (!$isUtc && !$isZoned) {
            return $value; // floating — leave as-is
        }

        return $value->setTimezone($this->targetTimezone);
    }

    private function intervalToMinutes(\DateInterval $interval): int
    {
        return ($interval->days !== false ? $interval->days * 24 * 60 : 0) +
               $interval->h * 60 +
               $interval->i;
    }

    private function extractIntProperty(VEvent $vevent, string $name): int
    {
        $prop = $vevent->getProperty($name);
        return $prop !== null ? (int) $prop->getValue()->getRawValue() : 0;
    }

    private function extractStringProperty(VEvent $vevent, string $name): ?string
    {
        $prop = $vevent->getProperty($name);
        return $prop !== null ? $prop->getValue()->getRawValue() : null;
    }
}
