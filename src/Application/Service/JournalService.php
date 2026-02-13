<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Journal;
use WebCalendar\Core\Domain\Repository\JournalRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\DateRange;

/**
 * Service for orchestrating Journal-related business logic.
 */
final readonly class JournalService
{
    public function __construct(
        private JournalRepositoryInterface $journalRepository
    ) {
    }

    public function getJournalById(EventId $id): ?Journal
    {
        return $this->journalRepository->findById($id);
    }

    /**
     * @return Journal[]
     */
    public function getJournalsInDateRange(DateRange $range, ?string $user = null): array
    {
        return $this->journalRepository->findByDateRange($range, $user);
    }

    public function createJournal(Journal $journal): void
    {
        $this->journalRepository->save($journal);
    }

    public function updateJournal(Journal $journal): void
    {
        $this->journalRepository->save($journal);
    }

    public function deleteJournal(EventId $id): void
    {
        $this->journalRepository->delete($id);
    }
}
