<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Journal;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Exception\EventNotFoundException;
use WebCalendar\Core\Domain\Repository\JournalRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for orchestrating Journal-related business logic.
 */
final readonly class JournalService
{
    private LoggerInterface $logger;

    public function __construct(
        private JournalRepositoryInterface $journalRepository,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
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

    /**
     * Creates a new journal.
     */
    public function createJournal(Journal $journal, User $actor): void
    {
        $this->logger->info('Journal created', ['id' => $journal->id()->value(), 'actor' => $actor->login()]);
        $this->journalRepository->save($journal);
    }

    /**
     * Updates an existing journal.
     * 
     * @throws AuthorizationException if actor is not the owner or admin
     */
    public function updateJournal(Journal $journal, User $actor): void
    {
        $this->assertCanModify($journal, $actor, 'update journal');
        $this->logger->info('Journal updated', ['id' => $journal->id()->value(), 'actor' => $actor->login()]);
        $this->journalRepository->save($journal);
    }

    /**
     * Deletes a journal.
     *
     * @throws EventNotFoundException if the journal does not exist.
     * @throws AuthorizationException if actor is not the owner or admin
     */
    public function deleteJournal(EventId $id, User $actor): void
    {
        $journal = $this->journalRepository->findById($id);
        if ($journal === null) {
            throw EventNotFoundException::forId($id);
        }

        $this->assertCanModify($journal, $actor, 'delete journal');
        $this->logger->info('Journal deleted', ['id' => $id->value(), 'actor' => $actor->login()]);
        $this->journalRepository->delete($id);
    }

    /**
     * Asserts that the actor can modify the journal.
     */
    private function assertCanModify(Journal $journal, User $actor, string $action): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if ($journal->createdBy() === $actor->login()) {
            return;
        }

        throw AuthorizationException::notOwner($action, $journal->id()->value(), $actor->login());
    }
}
