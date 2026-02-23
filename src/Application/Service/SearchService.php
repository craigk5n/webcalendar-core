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
final readonly class SearchService
{
    private LoggerInterface $logger;

    public function __construct(
        private EventRepositoryInterface $eventRepository,
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
}
