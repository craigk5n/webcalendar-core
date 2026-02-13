<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

/**
 * Interface for Site Extras (custom fields) persistence.
 */
interface SiteExtraRepositoryInterface
{
    /**
     * @return array<string, mixed> Map of field name to value for an event.
     */
    public function getForEvent(int $eventId): array;

    /**
     * @param array<string, mixed> $extras Map of field name to value.
     */
    public function saveForEvent(int $eventId, array $extras): void;

    public function deleteForEvent(int $eventId): void;
}
