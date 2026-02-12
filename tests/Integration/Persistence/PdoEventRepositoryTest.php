<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use PDO;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Infrastructure\Persistence\PdoEventRepository;

final class PdoEventRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PdoEventRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $this->loadSchema();
        
        $this->repository = new PdoEventRepository($this->pdo);
    }

    private function loadSchema(): void
    {
        $path = __DIR__ . '/../../../src/Infrastructure/Persistence/sqlite-schema.sql';
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException("Failed to load schema from $path");
        }
        $statements = explode(';', $sql);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                $this->pdo->exec($statement);
            }
        }
    }

    public function testSaveAndFindById(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $event = new Event(
            id: new EventId(0),
            uid: 'uid-123',
            name: 'Test Event',
            description: 'Desc',
            location: 'Location',
            start: $start,
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $this->repository->save($event);

        // Max ID should be 1
        $foundEvent = $this->repository->findById(new EventId(1));

        $this->assertNotNull($foundEvent);
        $this->assertSame('uid-123', $foundEvent->uid());
        $this->assertSame('Test Event', $foundEvent->name());
        $this->assertSame(60, $foundEvent->duration());
        $this->assertSame('2026-02-11 10:00:00', $foundEvent->start()->format('Y-m-d H:i:s'));
    }

    public function testFindByUid(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $event = new Event(
            id: new EventId(0),
            uid: 'unique-uid',
            name: 'Unique Event',
            description: '',
            location: '',
            start: $start,
            duration: 30,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $this->repository->save($event);

        $foundEvent = $this->repository->findByUid('unique-uid');

        $this->assertNotNull($foundEvent);
        $this->assertSame('Unique Event', $foundEvent->name());
    }

    public function testFindByDateRange(): void
    {
        $date1 = new \DateTimeImmutable('2026-02-10 10:00:00');
        $date2 = new \DateTimeImmutable('2026-02-15 10:00:00');
        
        $event1 = new Event(new EventId(0), 'u1', 'E1', '', '', $date1, 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC);
        $event2 = new Event(new EventId(0), 'u2', 'E2', '', '', $date2, 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC);

        $this->repository->save($event1);
        $this->repository->save($event2);

        $range = new DateRange(
            new \DateTimeImmutable('2026-02-01'),
            new \DateTimeImmutable('2026-02-12')
        );

        $events = $this->repository->findByDateRange($range);
        
        $this->assertCount(1, $events);
        $this->assertSame('E1', $events[0]->name());
    }
}
