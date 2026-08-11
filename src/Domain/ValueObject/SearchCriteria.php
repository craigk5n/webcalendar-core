<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventType;

/**
 * One shared query surface for event filtering (Epic 25) — the criteria
 * both apps' Filter Bars translate into. All filters are optional and
 * combine with AND; list filters match any-of within the list.
 *
 * Tags share the categories table, so tag filtering is category-id
 * filtering with tag ids.
 *
 * `eventIds` narrows an id set the caller has already decided on, rather
 * than searching the table from scratch. A calendar view needs this: it
 * has resolved which events the viewer may see, and has picked its date
 * window with recurrence-aware logic (a weekly event starting in January
 * belongs in a March view, which the `range` filter's plain `cal_date`
 * comparison would exclude). Filtering that set here keeps the permission
 * and recurrence rules where they already work, and can only ever remove
 * rows — so a Filter Bar built on it cannot widen what a viewer sees.
 *
 * Distance filtering is a bounding-box prefilter around the given point
 * (portable across MySQL/PostgreSQL/SQLite): matches fall within the
 * box that circumscribes the radius, so corner results up to ~1.41×
 * the radius can appear. Callers needing exact circles refine client-side.
 */
final class SearchCriteria
{
    public const MAX_LIMIT = 500;

    /**
     * @param ?string $keyword       Matches name or description.
     * @param list<int> $categoryIds Category (or tag) ids, any-of.
     * @param list<int> $venueIds    Venue ids, any-of.
     * @param list<int> $organizerIds Organizer ids, any-of.
     * @param ?DateRange $range      Calendar-date window.
     * @param list<EventType> $types Entry types, any-of.
     * @param ?float $nearLatitude   Distance filter center (with the other two).
     * @param ?float $nearLongitude  Distance filter center (with the other two).
     * @param ?float $radiusKm       Distance filter radius (with the other two).
     * @param int $limit             Page size, 1..MAX_LIMIT.
     * @param int $offset            Rows to skip.
     * @param list<int> $eventIds    Restrict to these event ids; `[]` means
     *        no restriction. Declared last to keep positional callers of
     *        earlier versions working.
     */
    public function __construct(
        public readonly ?string $keyword = null,
        public readonly array $categoryIds = [],
        public readonly array $venueIds = [],
        public readonly array $organizerIds = [],
        public readonly ?DateRange $range = null,
        public readonly array $types = [],
        public readonly ?float $nearLatitude = null,
        public readonly ?float $nearLongitude = null,
        public readonly ?float $radiusKm = null,
        public readonly int $limit = 100,
        public readonly int $offset = 0,
        public readonly array $eventIds = [],
    ) {
        $distanceParts = [$this->nearLatitude, $this->nearLongitude, $this->radiusKm];
        $given = count(array_filter($distanceParts, static fn (?float $v): bool => $v !== null));
        if ($given !== 0 && $given !== 3) {
            throw new \InvalidArgumentException(
                'Distance filtering needs latitude, longitude and radius together.'
            );
        }
        if ($this->radiusKm !== null && $this->radiusKm <= 0.0) {
            throw new \InvalidArgumentException('Distance radius must be positive.');
        }
        if ($this->nearLatitude !== null && ($this->nearLatitude < -90.0 || $this->nearLatitude > 90.0)) {
            throw new \InvalidArgumentException('Latitude must be between -90 and 90.');
        }
        if ($this->nearLongitude !== null && ($this->nearLongitude < -180.0 || $this->nearLongitude > 180.0)) {
            throw new \InvalidArgumentException('Longitude must be between -180 and 180.');
        }
        if ($this->limit < 1 || $this->limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(
                sprintf('Limit must be between 1 and %d.', self::MAX_LIMIT)
            );
        }
        if ($this->offset < 0) {
            throw new \InvalidArgumentException('Offset cannot be negative.');
        }
    }

    public function hasDistanceFilter(): bool
    {
        return $this->radiusKm !== null;
    }
}
