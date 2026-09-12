<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventCollection;
use WebCalendar\Core\Domain\ValueObject\EventScope;
use WebCalendar\Core\Domain\ValueObject\SearchCriteria;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for searching events and tasks.
 *
 * Both methods take a required {@see EventScope}. Search used to scope by a
 * pair of nullable arguments, and criteria search could not be scoped at all,
 * which meant either could return every user's PRIVATE and CONFIDENTIAL
 * entries to whoever called it.
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
     * Searches for events by keyword, within $scope.
     */
    public function search(
        string $keyword,
        EventScope $scope,
        ?DateRange $range = null
    ): EventCollection {
        $this->logger->debug('Searching events', [
            'keyword' => $keyword,
            'user' => $scope->user()?->login(),
            'access' => $scope->accessLevel(),
            'administrative' => $scope->isAdministrative(),
        ]);

        return $this->eventRepository->search($keyword, $scope, $range);
    }

    /**
     * Filtered, paginated search — the Filter Bar surface (Epic 25).
     * All filtering happens at the repository so no load-all-and-filter
     * path exists.
     */
    public function searchByCriteria(SearchCriteria $criteria, EventScope $scope): EventCollection
    {
        $this->logger->debug('Searching events by criteria', [
            'keyword' => $criteria->keyword,
            'limit' => $criteria->limit,
            'offset' => $criteria->offset,
            'user' => $scope->user()?->login(),
            'access' => $scope->accessLevel(),
            'administrative' => $scope->isAdministrative(),
        ]);

        return $this->eventRepository->searchByCriteria($criteria, $scope);
    }
}
