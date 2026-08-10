<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventCollection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for searching events and tasks.
 */
final class SearchService
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Searches for events by keyword and optional filters.
     */
    public function search(string $keyword, ?DateRange $range = null, ?User $user = null): EventCollection
    {
        $this->logger->debug('Searching events', ['keyword' => $keyword, 'user' => $user?->login()]);
        return $this->eventRepository->search($keyword, $range, $user);
    }

    /**
     * Filtered, paginated search — the Filter Bar surface (Epic 25).
     * All filtering happens at the repository so no load-all-and-filter
     * path exists.
     */
    public function searchByCriteria(\WebCalendar\Core\Domain\ValueObject\SearchCriteria $criteria): EventCollection
    {
        $this->logger->debug('Searching events by criteria', [
            'keyword' => $criteria->keyword,
            'limit' => $criteria->limit,
            'offset' => $criteria->offset,
        ]);
        return $this->eventRepository->searchByCriteria($criteria);
    }
}
