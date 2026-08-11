<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Import;

use WebCalendar\Core\Application\DTO\MappedExternalEvent;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\Organizer;
use WebCalendar\Core\Domain\Entity\Venue;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\OrganizerId;
use WebCalendar\Core\Domain\ValueObject\VenueId;

/**
 * Maps one Eventbrite v3 API event object into domain terms — Epic 27.
 * Pure mapping: no HTTP, no persistence. Transport, auth and scheduling
 * are app-side; per the parity plan there is no hosted import service.
 *
 * Uids are `eventbrite-{id}@{host}` so re-imports dedupe on the
 * provider's stable event id. Online events map their URL onto the
 * RFC 7986 conference fields (Epic 26).
 */
final class EventbriteMapper
{
    public function __construct(
        private readonly string $uidHost,
    ) {
    }

    /**
     * @param array<string, mixed> $payload One decoded Eventbrite event.
     * @throws \InvalidArgumentException When id, name or start are missing.
     */
    public function map(array $payload, string $createdBy): MappedExternalEvent
    {
        $id = isset($payload['id']) && is_scalar($payload['id']) ? (string) $payload['id'] : '';
        $name = $this->text($payload['name'] ?? null);
        if ($id === '' || $name === '') {
            throw new \InvalidArgumentException('Eventbrite event needs an id and a name.');
        }

        $start = $this->localTime($payload['start'] ?? null);
        if ($start === null) {
            throw new \InvalidArgumentException(sprintf('Eventbrite event %s has no usable start.', $id));
        }
        $end = $this->localTime($payload['end'] ?? null);
        $duration = $end !== null && $end > $start
            ? (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60)
            : 60;

        $warnings = [];
        $venue = null;
        $rawVenue = $payload['venue'] ?? null;
        if (is_array($rawVenue)) {
            $venue = $this->mapVenue($rawVenue, $warnings);
        }

        $organizer = null;
        $rawOrganizer = $payload['organizer'] ?? null;
        if (is_array($rawOrganizer) && is_scalar($rawOrganizer['name'] ?? null) && (string) $rawOrganizer['name'] !== '') {
            $organizer = new Organizer(
                id: new OrganizerId(0),
                name: (string) $rawOrganizer['name'],
                url: is_scalar($rawOrganizer['url'] ?? null) && (string) $rawOrganizer['url'] !== ''
                    ? (string) $rawOrganizer['url']
                    : null,
            );
        }

        $isOnline = ($payload['online_event'] ?? false) === true;
        $url = is_scalar($payload['url'] ?? null) ? (string) $payload['url'] : null;

        $logo = $payload['logo'] ?? null;
        $imageUrl = is_array($logo) && is_scalar($logo['url'] ?? null) && (string) $logo['url'] !== ''
            ? (string) $logo['url']
            : null;

        $event = new Event(
            id: new EventId(0),
            uid: sprintf('eventbrite-%s@%s', $id, $this->uidHost),
            name: $name,
            description: $this->text($payload['description'] ?? null),
            location: $venue !== null ? $venue->name() : '',
            start: $start,
            duration: $duration,
            createdBy: $createdBy,
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            conferenceUrl: $isOnline ? $url : null,
            conferenceLabel: $isOnline ? 'Eventbrite Online Event' : null,
        );

        return new MappedExternalEvent($event, $venue, $organizer, $imageUrl, $warnings);
    }

    /**
     * @param array<string, mixed> $rawVenue Venue object.
     * @param list<string> $warnings By reference.
     */
    private function mapVenue(array $rawVenue, array &$warnings): ?Venue
    {
        $name = is_scalar($rawVenue['name'] ?? null) ? (string) $rawVenue['name'] : '';
        if ($name === '') {
            return null;
        }

        $address = is_array($rawVenue['address'] ?? null) ? $rawVenue['address'] : [];
        $lat = is_numeric($address['latitude'] ?? null) ? (float) $address['latitude'] : null;
        $lon = is_numeric($address['longitude'] ?? null) ? (float) $address['longitude'] : null;
        if (($lat === null) !== ($lon === null)) {
            $warnings[] = sprintf('Venue "%s": incomplete coordinates dropped.', $name);
            $lat = null;
            $lon = null;
        }

        $field = static fn (string $key): ?string =>
            is_scalar($address[$key] ?? null) && (string) $address[$key] !== '' ? (string) $address[$key] : null;

        return new Venue(
            id: new VenueId(0),
            name: $name,
            address: $field('address_1'),
            city: $field('city'),
            state: $field('region'),
            zip: $field('postal_code'),
            country: $field('country'),
            latitude: $lat,
            longitude: $lon,
        );
    }

    /**
     * Eventbrite times: {timezone, local, utc}. The local wall time in
     * the event's own timezone is what WebCalendar stores.
     */
    private function localTime(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_array($raw) || !is_scalar($raw['local'] ?? null)) {
            return null;
        }
        // An empty timezone string would make DateTimeZone throw, costing the
        // event its start date entirely; treat it the same as a missing one.
        $timezone = is_scalar($raw['timezone'] ?? null) ? (string) $raw['timezone'] : '';
        if ('' === $timezone) {
            $timezone = 'UTC';
        }
        try {
            return new \DateTimeImmutable((string) $raw['local'], new \DateTimeZone($timezone));
        } catch (\Exception) {
            return null;
        }
    }

    private function text(mixed $raw): string
    {
        if (is_array($raw) && is_scalar($raw['text'] ?? null)) {
            return (string) $raw['text'];
        }
        return is_scalar($raw) ? (string) $raw : '';
    }
}
