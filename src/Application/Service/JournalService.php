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
use WebCalendar\Core\Domain\ValueObject\EventScope;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for orchestrating Journal-related business logic.
 */
final class JournalService
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly JournalRepositoryInterface $journalRepository,
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
    public function getJournalsInDateRange(DateRange $range, EventScope $scope): array
    {
        return $this->journalRepository->findByDateRange($range, $scope);
    }

    /**
     * Creates a new journal.
     *
     * @throws \InvalidArgumentException if the journal already has an id
     * @throws AuthorizationException if a non-admin creates a journal for
     *   another user
     */
    public function createJournal(Journal $journal, User $actor): void
    {
        if ($journal->id()->value() !== 0) {
            throw new \InvalidArgumentException(
                'createJournal requires a new journal (id 0); use updateJournal'
            );
        }
        if (!$actor->isAdmin() && $journal->createdBy() !== $actor->login()) {
            throw AuthorizationException::notSelf(
                'create journal',
                $journal->createdBy(),
                $actor->login()
            );
        }

        $this->logger->info('Journal created', ['id' => $journal->id()->value(), 'actor' => $actor->login()]);
        $this->journalRepository->save($journal);
    }

    /**
     * Updates an existing journal.
     *
     * Authorization is checked against the stored journal, not the
     * incoming one.
     *
     * @throws EventNotFoundException if no journal exists with that id
     * @throws AuthorizationException if actor is not the owner or admin, or a
     *   non-admin tries to change the owner
     */
    public function updateJournal(Journal $journal, User $actor): void
    {
        $existing = $this->journalRepository->findById($journal->id());
        if ($existing === null) {
            throw EventNotFoundException::forId($journal->id());
        }

        $this->assertCanModify($existing, $actor, 'update journal');
        if (
            !$actor->isAdmin()
            && $journal->createdBy() !== $existing->createdBy()
        ) {
            throw AuthorizationException::notOwner(
                'reassign journal',
                $journal->id()->value(),
                $actor->login()
            );
        }

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
