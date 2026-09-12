<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use WebCalendar\Core\Domain\Entity\Journal;
use WebCalendar\Core\Domain\Entity\Task;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventScope;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Infrastructure\Persistence\PdoJournalRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoTaskRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

/**
 * Tasks and journals are webcal_entry rows like events, so they carry
 * cal_access -- but their date-range queries applied no access filter at all,
 * and with a null user returned every user's private entries. These paths had
 * no test coverage, which is how that survived.
 */
final class PdoTaskJournalScopeTest extends RepositoryTestCase
{
    private PdoTaskRepository $tasks;
    private PdoJournalRepository $journals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tasks = new PdoTaskRepository($this->pdo);
        $this->journals = new PdoJournalRepository($this->pdo);

        $start = new \DateTimeImmutable('2026-02-11 10:00:00');

        foreach ([
            ['t-pub', 'Public task', 'jdoe', AccessLevel::PUBLIC],
            ['t-priv', 'PRIVATE: tax return', 'jdoe', AccessLevel::PRIVATE],
            ['t-other', 'Someone elses task', 'bsmith', AccessLevel::PUBLIC],
            ['t-other-priv', 'PRIVATE: bsmith therapy', 'bsmith', AccessLevel::PRIVATE],
        ] as [$uid, $name, $owner, $access]) {
            $this->tasks->save(new Task(
                new EventId(0), $uid, $name, '', '', $start, 60, $owner, EventType::TASK, $access
            ));
        }

        foreach ([
            ['j-pub', 'Public journal', 'jdoe', AccessLevel::PUBLIC],
            ['j-priv', 'PRIVATE: diary', 'jdoe', AccessLevel::PRIVATE],
            ['j-other-priv', 'PRIVATE: bsmith diary', 'bsmith', AccessLevel::PRIVATE],
        ] as [$uid, $name, $owner, $access]) {
            $this->journals->save(new Journal(
                new EventId(0), $uid, $name, '', '', $start, 0, $owner, EventType::JOURNAL, $access
            ));
        }
    }

    private function range(): \WebCalendar\Core\Domain\ValueObject\DateRange
    {
        return new \WebCalendar\Core\Domain\ValueObject\DateRange(
            new \DateTimeImmutable('2026-02-01'),
            new \DateTimeImmutable('2026-02-28')
        );
    }

    private function user(string $login): User
    {
        return new User($login, 'Test', 'User', "$login@example.com", false, true);
    }

    /**
     * @param array<int, Task|Journal> $entries
     * @return string[]
     */
    private function namesOf(array $entries): array
    {
        $names = array_map(static fn (object $e): string => $e->name(), $entries);
        sort($names);
        return $names;
    }

    // -- tasks ------------------------------------------------------------

    public function testTaskScopeHidesOtherPeoplesPrivateTasks(): void
    {
        $found = $this->tasks->findByDateRange($this->range(), EventScope::forUser($this->user('jdoe')));

        $this->assertSame(
            ['PRIVATE: tax return', 'Public task', 'Someone elses task'],
            $this->namesOf($found)
        );
        $this->assertNotContains('PRIVATE: bsmith therapy', $this->namesOf($found));
    }

    public function testTaskPublicOnlyScope(): void
    {
        $found = $this->tasks->findByDateRange($this->range(), EventScope::publicOnly());

        $this->assertSame(['Public task', 'Someone elses task'], $this->namesOf($found));
    }

    public function testTaskAdministrativeScopeStillSeesEverything(): void
    {
        $found = $this->tasks->findByDateRange($this->range(), EventScope::administrative());

        $this->assertCount(4, $found);
    }

    public function testTaskScopeCanBePinnedToOneCalendar(): void
    {
        $found = $this->tasks->findByDateRange(
            $this->range(),
            EventScope::forUser($this->user('jdoe'))->limitedToUsers(['jdoe'])
        );

        $this->assertSame(['PRIVATE: tax return', 'Public task'], $this->namesOf($found));
    }

    // -- journals ---------------------------------------------------------

    public function testJournalScopeHidesOtherPeoplesPrivateJournals(): void
    {
        $found = $this->journals->findByDateRange($this->range(), EventScope::forUser($this->user('jdoe')));

        $this->assertSame(['PRIVATE: diary', 'Public journal'], $this->namesOf($found));
        $this->assertNotContains('PRIVATE: bsmith diary', $this->namesOf($found));
    }

    public function testJournalPublicOnlyScope(): void
    {
        $found = $this->journals->findByDateRange($this->range(), EventScope::publicOnly());

        $this->assertSame(['Public journal'], $this->namesOf($found));
    }

    public function testJournalAdministrativeScopeStillSeesEverything(): void
    {
        $found = $this->journals->findByDateRange($this->range(), EventScope::administrative());

        $this->assertCount(3, $found);
    }

    /**
     * The queries select on cal_type, so a scoped task read must not pick up
     * journals and vice versa.
     */
    public function testTaskAndJournalQueriesStayOnTheirOwnEntryTypes(): void
    {
        $tasks = $this->namesOf($this->tasks->findByDateRange($this->range(), EventScope::administrative()));
        $journals = $this->namesOf($this->journals->findByDateRange($this->range(), EventScope::administrative()));

        $this->assertSame([], array_intersect($tasks, $journals));
    }
}
