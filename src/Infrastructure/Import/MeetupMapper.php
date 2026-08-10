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
 * Maps one Meetup API event object into domain terms — Epic 27. Pure
 * mapping, same contract as EventbriteMapper: no HTTP, no persistence.
 *
 * Uids are `meetup-{id}@{host}`. Online events map their onlineVenue
 * URL onto the RFC 7986 conference fields (Epic 26); the hosting group
 * becomes the organizer.
 */
final class MeetupMapper
{
    public function __construct(
        private readonly string $uidHost,
    ) {
    }

    /**
     * @param array<string, mixed> $payload One decoded Meetup event.
     * @throws \InvalidArgumentException When id, title or dateTime are missing.
     */
    public function map(array $payload, string $createdBy): MappedExternalEvent
    {
        $id = isset($payload['id']) && is_scalar($payload['id']) ? (string) $payload['id'] : '';
        $title = is_scalar($payload['title'] ?? null) ? (string) $payload['title'] : '';
        if ($id === '' || $title === '') {
            throw new \InvalidArgumentException('Meetup event needs an id and a title.');
        }

        $start = $this->time($payload['dateTime'] ?? null);
        if ($start === null) {
            throw new \InvalidArgumentException(sprintf('Meetup event %s has no usable dateTime.', $id));
        }
        $end = $this->time($payload['endTime'] ?? null);
        $duration = $end !== null && $end > $start
            ? (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60)
            : 60;

        $venue = null;
        $rawVenue = $payload['venue'] ?? null;
        if (is_array($rawVenue) && is_scalar($rawVenue['name'] ?? null) && (string) $rawVenue['name'] !== '') {
            $lat = is_numeric($rawVenue['lat'] ?? null) ? (float) $rawVenue['lat'] : null;
            $lng = is_numeric($rawVenue['lng'] ?? null) ? (float) $rawVenue['lng'] : null;
            if (($lat === null) !== ($lng === null)) {
                $lat = null;
                $lng = null;
            }
            $field = static fn (string $key): ?string =>
                is_scalar($rawVenue[$key] ?? null) && (string) $rawVenue[$key] !== ''
                    ? (string) $rawVenue[$key]
                    : null;
            $venue = new Venue(
                id: new VenueId(0),
                name: (string) $rawVenue['name'],
                address: $field('address'),
                city: $field('city'),
                state: $field('state'),
                zip: $field('postalCode'),
                country: $field('country'),
                latitude: $lat,
                longitude: $lng,
            );
        }

        $organizer = null;
        $group = $payload['group'] ?? null;
        if (is_array($group) && is_scalar($group['name'] ?? null) && (string) $group['name'] !== '') {
            $urlname = is_scalar($group['urlname'] ?? null) ? (string) $group['urlname'] : '';
            $organizer = new Organizer(
                id: new OrganizerId(0),
                name: (string) $group['name'],
                url: $urlname !== '' ? 'https://www.meetup.com/' . $urlname . '/' : null,
            );
        }

        $online = $payload['onlineVenue'] ?? null;
        $conferenceUrl = is_array($online) && is_scalar($online['url'] ?? null) && (string) $online['url'] !== ''
            ? (string) $online['url']
            : null;
        $conferenceLabel = null;
        if ($conferenceUrl !== null) {
            $type = is_array($online) && is_scalar($online['type'] ?? null) ? (string) $online['type'] : '';
            $conferenceLabel = $type !== '' ? ucfirst($type) : 'Online Meetup';
        }

        $event = new Event(
            id: new EventId(0),
            uid: sprintf('meetup-%s@%s', $id, $this->uidHost),
            name: $title,
            description: is_scalar($payload['description'] ?? null) ? (string) $payload['description'] : '',
            location: $venue !== null ? $venue->name() : '',
            start: $start,
            duration: $duration,
            createdBy: $createdBy,
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            conferenceUrl: $conferenceUrl,
            conferenceLabel: $conferenceLabel,
        );

        $imageUrl = is_scalar($payload['imageUrl'] ?? null) && (string) $payload['imageUrl'] !== ''
            ? (string) $payload['imageUrl']
            : null;

        return new MappedExternalEvent($event, $venue, $organizer, $imageUrl);
    }

    /**
     * Meetup times are ISO-8601 with an offset (e.g. 2026-10-07T18:30:00-04:00).
     */
    private function time(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_scalar($raw) || (string) $raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable((string) $raw);
        } catch (\Exception) {
            return null;
        }
    }
}
