<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Repository\SiteExtraRepositoryInterface;

/**
 * Service for managing custom event fields (Site Extras).
 */
final readonly class SiteExtraService
{
    public function __construct(
        private SiteExtraRepositoryInterface $siteExtraRepository
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtrasForEvent(int $eventId): array
    {
        return $this->siteExtraRepository->getForEvent($eventId);
    }

    /**
     * @param array<string, mixed> $extras
     */
    public function saveExtrasForEvent(int $eventId, array $extras): void
    {
        $this->siteExtraRepository->saveForEvent($eventId, $extras);
    }

    public function deleteExtrasForEvent(int $eventId): void
    {
        $this->siteExtraRepository->deleteForEvent($eventId);
    }
}
