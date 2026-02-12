<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventCollection;

/**
 * Service for searching events and tasks.
 */
final readonly class SearchService
{
    public function __construct(
        private EventRepositoryInterface $eventRepository
    ) {
    }

    /**
     * Searches for events by keyword and optional filters.
     */
    public function search(string $keyword, ?DateRange $range = null, ?User $user = null): EventCollection
    {
        return $this->eventRepository->search($keyword, $range, $user);
    }
}
