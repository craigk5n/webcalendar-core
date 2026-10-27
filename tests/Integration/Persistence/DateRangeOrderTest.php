<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\Journal;
use WebCalendar\Core\Domain\Entity\Task;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventScope;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Infrastructure\Persistence\PdoEventRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoJournalRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoTaskRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

/**
 * findByDateRange() returned rows in whatever order the server chose. SQLite
 * and MySQL 8 happened to hand them back sorted often enough to hide it;
 * MariaDB 10.4 did not. Every fixture here is saved latest-first, so storage
 * order is the wrong answer.
 */
final class DateRangeOrderTest extends RepositoryTestCase
{
    private const SAVED = [
        ['late', '2026-03-20 14:00:00'],
        ['same-day-late', '2026-03-05 15:00:00'],
        ['early', '2026-03-05 09:00:00'],
    ];

    private const EXPECTED = ['early', 'same-day-late', 'late'];

    private function range(): DateRange
    {
        return new DateRange(
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-31')
        );
    }

    /**
     * @param array<int, Event|Task|Journal> $entries
     * @return list<string>
     */
    private function namesOf(array $entries): array
    {
        return array_values(array_map(static fn (object $e): string => $e->name(), $entries));
    }

    public function testEventsComeBackInDateThenTimeOrder(): void
    {
        $repository = new PdoEventRepository($this->pdo);
        foreach (self::SAVED as [$name, $start]) {
            $repository->save(new Event(
                new EventId(0), "e-$name", $name, '', '',
                new \DateTimeImmutable($start), 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC
            ));
        }

        $this->assertSame(
            self::EXPECTED,
            $this->namesOf($repository->findByDateRange($this->range(), EventScope::administrative()))
        );
    }

    public function testTasksComeBackInDateThenTimeOrder(): void
    {
        $repository = new PdoTaskRepository($this->pdo);
        foreach (self::SAVED as [$name, $start]) {
            $repository->save(new Task(
                new EventId(0), "t-$name", $name, '', '',
                new \DateTimeImmutable($start), 60, 'admin', EventType::TASK, AccessLevel::PUBLIC
            ));
        }

        $this->assertSame(
            self::EXPECTED,
            $this->namesOf($repository->findByDateRange($this->range(), EventScope::administrative()))
        );
    }

    public function testJournalsComeBackInDateThenTimeOrder(): void
    {
        $repository = new PdoJournalRepository($this->pdo);
        foreach (self::SAVED as [$name, $start]) {
            $repository->save(new Journal(
                new EventId(0), "j-$name", $name, '', '',
                new \DateTimeImmutable($start), 0, 'admin', EventType::JOURNAL, AccessLevel::PUBLIC
            ));
        }

        $this->assertSame(
            self::EXPECTED,
            $this->namesOf($repository->findByDateRange($this->range(), EventScope::administrative()))
        );
    }
}
