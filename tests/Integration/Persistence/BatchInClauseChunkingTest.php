<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Infrastructure\Persistence\PdoCategoryRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoEventRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

/**
 * Regression tests for the prepared-statement placeholder ceiling.
 *
 * MySQL caps placeholders at 65,535 per statement. The batch loaders used to
 * bind one placeholder per event id, so a large export (`wp webcal export`
 * with >= ~65k events) failed with "Prepared statement contains too many
 * placeholders". The loaders must chunk their id lists.
 *
 * The suite runs on SQLite, whose own ceiling is a compile-time option that
 * distro builds often raise past MySQL's, so the DB cannot be relied on to
 * enforce anything. Instead createPdo() wraps the connection in
 * PlaceholderCeilingPdo, which rejects any statement carrying more
 * positional placeholders than MySQL accepts — an unchunked IN() over
 * MANY_IDS ids fails here exactly as it fails in production. Marker rows are
 * seeded on the first and last id to prove results are merged across chunks.
 */
final class BatchInClauseChunkingTest extends RepositoryTestCase
{
    /**
     * Enough ids that an unchunked IN() exceeds MySQL's ceiling, while a
     * loader chunking at <= 65,535 must split it into multiple queries.
     */
    private const MANY_IDS = 70000;

    private PdoEventRepository $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new PdoEventRepository($this->pdo);
        $this->seedEvents(self::MANY_IDS);
    }

    protected function createPdo(string $dbUrl, ?string $dbUser, ?string $dbPass): \PDO
    {
        return new PlaceholderCeilingPdo($dbUrl, $dbUser, $dbPass);
    }

    public function testFindByDateRangeLoadsRecurrenceDataAcrossChunks(): void
    {
        // EXDATE rows for the first and last event land in different IN()
        // chunks of the batch recurrence load.
        $this->pdo->exec(
            'INSERT INTO webcal_entry_repeats_not (cal_id, cal_date, cal_exdate)
             VALUES (1, 20260716, 1), (' . self::MANY_IDS . ', 20260716, 1)'
        );

        $events = $this->events->findByDateRange(new DateRange(
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-31')
        ));

        $this->assertCount(self::MANY_IDS, $events);

        $markers = [];
        foreach ($events as $event) {
            $id = $event->id()->value();
            if ($id === 1 || $id === self::MANY_IDS) {
                $markers[$id] = $event;
            }
        }
        $first = $markers[1] ?? null;
        $last = $markers[self::MANY_IDS] ?? null;
        $this->assertNotNull($first);
        $this->assertNotNull($last);
        $this->assertCount(1, $first->recurrence()->exDate()->dates());
        $this->assertCount(1, $last->recurrence()->exDate()->dates());
    }

    public function testGetParticipantsBatchAcrossChunks(): void
    {
        $this->pdo->exec(
            'INSERT INTO webcal_entry_user (cal_id, cal_login)
             VALUES (1, \'alice\'), (' . self::MANY_IDS . ', \'bob\')'
        );

        $map = $this->events->getParticipantsBatch($this->manyEventIds());

        $this->assertCount(self::MANY_IDS, $map);
        $this->assertSame(['alice'], $map[1]);
        $this->assertSame(['bob'], $map[self::MANY_IDS]);
        $this->assertSame([], $map[2]);
    }

    public function testGetForEventsBatchAcrossChunks(): void
    {
        $this->pdo->exec(
            "INSERT INTO webcal_categories (cat_id, cat_owner, cat_name, cat_color)
             VALUES (7, '', 'Global', '#ff0000')"
        );
        $this->pdo->exec(
            'INSERT INTO webcal_entry_categories (cal_id, cat_id, cat_order, cat_owner)
             VALUES (1, 7, 1, \'\'), (' . self::MANY_IDS . ', 7, 1, \'\')'
        );

        $categories = new PdoCategoryRepository($this->pdo);
        $map = $categories->getForEventsBatch($this->manyEventIds(), '');

        $this->assertSame(7, $map[1]['id']);
        $this->assertSame(7, $map[self::MANY_IDS]['id']);
        $this->assertArrayNotHasKey(2, $map);
    }

    /**
     * @return EventId[]
     */
    private function manyEventIds(): array
    {
        return array_map(
            static fn (int $id): EventId => new EventId($id),
            range(1, self::MANY_IDS)
        );
    }

    /**
     * Seed minimal event rows with raw multi-row inserts — one
     * repository->save() per event would dominate the test runtime.
     */
    private function seedEvents(int $count): void
    {
        $this->pdo->beginTransaction();
        for ($from = 1; $from <= $count; $from += 500) {
            $to = min($from + 499, $count);
            $values = [];
            for ($id = $from; $id <= $to; $id++) {
                $values[] = "($id, 'admin', 20260715, 60, 'Event $id')";
            }
            $this->pdo->exec(
                'INSERT INTO webcal_entry (cal_id, cal_create_by, cal_date, cal_duration, cal_name) VALUES '
                . implode(',', $values)
            );
        }
        $this->pdo->commit();
    }
}

/**
 * PDO wrapper enforcing MySQL's 65,535-placeholder-per-statement ceiling,
 * which local SQLite builds often do not enforce themselves.
 */
final class PlaceholderCeilingPdo extends \PDO
{
    private const MYSQL_MAX_PLACEHOLDERS = 65535;

    /**
     * @param array<int|string, mixed> $options
     */
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $count = substr_count($query, '?');
        if ($count > self::MYSQL_MAX_PLACEHOLDERS) {
            throw new \PDOException(
                "Prepared statement contains too many placeholders ({$count} > "
                . self::MYSQL_MAX_PLACEHOLDERS . ')'
            );
        }
        return parent::prepare($query, $options);
    }
}
