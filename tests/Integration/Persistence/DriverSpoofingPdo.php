<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use PDO;
use PDOStatement;

/**
 * A PDO subclass that records prepared SQL and reports a driver name of the
 * test's choosing.
 *
 * Why: `PdoTicketRepository::lockTicketType()` only emits
 * `SELECT ... FOR UPDATE` on MySQL and PostgreSQL, because SQLite has no such
 * clause. Core's integration tests run on SQLite, so the branch that matters
 * in production is the one they would never reach.
 *
 * This helper closes that gap the same way
 * {@see StrictPlaceholderPdo} closes the placeholder-reuse gap: the test
 * keeps its real SQLite database, but the repository is told it is talking to
 * MySQL. `FOR UPDATE` is stripped before the statement reaches SQLite so the
 * query still runs; the assertion is made against `$preparedSql`, which holds
 * what the repository actually asked for.
 */
final class DriverSpoofingPdo extends PDO
{
    /**
     * Driver name handed back for PDO::ATTR_DRIVER_NAME. Mutable so a single
     * connection (and therefore a single in-memory database) can be exercised
     * as both drivers within one test class.
     */
    public string $spoofDriver = 'sqlite';

    /** @var list<string> Every statement passed to prepare(), verbatim. */
    public array $preparedSql = [];

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            return $this->spoofDriver;
        }

        return parent::getAttribute($attribute);
    }

    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->preparedSql[] = $query;

        // SQLite cannot parse FOR UPDATE. Drop it so the spoofed-MySQL path
        // still executes end to end; the recorded SQL above is what the
        // assertions read.
        $runnable = str_replace('FOR UPDATE', '', $query);

        return parent::prepare($runnable, $options);
    }
}
