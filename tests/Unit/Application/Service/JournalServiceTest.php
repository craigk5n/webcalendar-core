<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\JournalService;
use WebCalendar\Core\Domain\Repository\JournalRepositoryInterface;
use WebCalendar\Core\Domain\Entity\Journal;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Exception\EventNotFoundException;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;

final class JournalServiceTest extends TestCase
{
    /** @var JournalRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $journalRepository;
    private JournalService $journalService;

    protected function setUp(): void
    {
        $this->journalRepository = $this->createMock(JournalRepositoryInterface::class);
        $this->journalService = new JournalService($this->journalRepository);
    }

    public function testGetJournalById(): void
    {
        $id = new EventId(1);
        $journal = $this->createJournal($id);
        
        $this->journalRepository->expects($this->once())
            ->method('findById')
            ->with($id)
            ->willReturn($journal);

        $this->assertSame($journal, $this->journalService->getJournalById($id));
    }


    public function testCreateJournalSavesNewEntryOwnedByActor(): void
    {
        $journal = $this->createJournal(new EventId(0), 'bob');

        $this->journalRepository->expects($this->once())->method('save')->with($journal);

        $this->journalService->createJournal($journal, $this->user('bob'));
    }

    public function testCreateJournalRejectsExistingId(): void
    {
        $this->journalRepository->expects($this->never())->method('save');

        $this->expectException(\InvalidArgumentException::class);
        $this->journalService->createJournal($this->createJournal(new EventId(7), 'bob'), $this->user('bob'));
    }

    public function testNonAdminCannotCreateJournalOwnedByAnotherUser(): void
    {
        $this->journalRepository->expects($this->never())->method('save');

        $this->expectException(AuthorizationException::class);
        $this->journalService->createJournal($this->createJournal(new EventId(0), 'alice'), $this->user('bob'));
    }

    public function testAdminCanCreateJournalOwnedByAnotherUser(): void
    {
        $journal = $this->createJournal(new EventId(0), 'alice');

        $this->journalRepository->expects($this->once())->method('save')->with($journal);

        $this->journalService->createJournal($journal, $this->user('admin', true));
    }

    public function testOwnerCanUpdateOwnJournal(): void
    {
        $id = new EventId(5);
        $updated = $this->createJournal($id, 'bob', 'Renamed');

        $this->journalRepository->method('findById')->with($id)->willReturn($this->createJournal($id, 'bob'));
        $this->journalRepository->expects($this->once())->method('save')->with($updated);

        $this->journalService->updateJournal($updated, $this->user('bob'));
    }

    public function testUpdateJournalChecksStoredOwnerNotIncomingEntity(): void
    {
        $id = new EventId(5);
        // Caller claims ownership of alice's entry by setting createdBy to itself.
        $forged = $this->createJournal($id, 'bob', 'Overwritten');

        $this->journalRepository->method('findById')->with($id)->willReturn($this->createJournal($id, 'alice'));
        $this->journalRepository->expects($this->never())->method('save');

        $this->expectException(AuthorizationException::class);
        $this->journalService->updateJournal($forged, $this->user('bob'));
    }

    public function testUpdateJournalThrowsWhenStoredEntryMissing(): void
    {
        // A non-journal id (or unknown id) is not returned by the typed findById.
        $id = new EventId(9);

        $this->journalRepository->method('findById')->with($id)->willReturn(null);
        $this->journalRepository->expects($this->never())->method('save');

        $this->expectException(EventNotFoundException::class);
        $this->journalService->updateJournal($this->createJournal($id, 'bob'), $this->user('bob'));
    }

    public function testOwnerCannotReassignJournalToAnotherUser(): void
    {
        $id = new EventId(5);

        $this->journalRepository->method('findById')->with($id)->willReturn($this->createJournal($id, 'bob'));
        $this->journalRepository->expects($this->never())->method('save');

        $this->expectException(AuthorizationException::class);
        $this->journalService->updateJournal($this->createJournal($id, 'alice'), $this->user('bob'));
    }

    public function testAdminCanUpdateAnyoneJournal(): void
    {
        $id = new EventId(5);
        $updated = $this->createJournal($id, 'alice', 'Fixed by admin');

        $this->journalRepository->method('findById')->with($id)->willReturn($this->createJournal($id, 'alice'));
        $this->journalRepository->expects($this->once())->method('save')->with($updated);

        $this->journalService->updateJournal($updated, $this->user('admin', true));
    }

    private function user(string $login, bool $isAdmin = false): User
    {
        return new User($login, 'First', 'Last', $login . '@example.com', $isAdmin, true);
    }

    private function createJournal(
        EventId $id,
        string $createdBy = 'admin',
        string $name = 'Journal 1'
    ): Journal {
        return new Journal(
            id: $id,
            uid: 'uid',
            name: $name,
            description: '',
            location: '',
            start: new \DateTimeImmutable(),
            duration: 0,
            createdBy: $createdBy,
            type: EventType::JOURNAL,
            access: AccessLevel::PUBLIC
        );
    }
}
