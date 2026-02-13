<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\JournalService;
use WebCalendar\Core\Domain\Repository\JournalRepositoryInterface;
use WebCalendar\Core\Domain\Entity\Journal;
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

    private function createJournal(EventId $id): Journal
    {
        return new Journal(
            id: $id,
            uid: 'uid',
            name: 'Journal 1',
            description: '',
            location: '',
            start: new \DateTimeImmutable(),
            duration: 0,
            createdBy: 'admin',
            type: EventType::JOURNAL,
            access: AccessLevel::PUBLIC
        );
    }
}
