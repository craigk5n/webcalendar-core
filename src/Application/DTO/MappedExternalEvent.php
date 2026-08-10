<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\DTO;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\Organizer;
use WebCalendar\Core\Domain\Entity\Venue;

/**
 * One external event (Eventbrite, Meetup, …) mapped into domain terms —
 * Epic 27. The venue/organizer arrive UNSAVED (id 0); the consuming
 * import path runs them through VenueService/OrganizerService
 * matchOrCreate and re-points the event at the persisted ids before
 * saving. The event's uid embeds the provider and its stable id
 * (`eventbrite-{id}@host`), so re-imports dedupe.
 *
 * @psalm-api Consumed by app-side connector transports.
 */
final class MappedExternalEvent
{
    /**
     * @param list<string> $warnings Mapping notes.
     */
    public function __construct(
        public readonly Event $event,
        public readonly ?Venue $venue,
        public readonly ?Organizer $organizer,
        public readonly ?string $imageUrl,
        public readonly array $warnings = [],
    ) {
    }
}
