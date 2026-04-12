<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * A PDO subclass that rejects any prepared statement containing a named
 * placeholder referenced more than once.
 *
 * Why: PDO's emulated-prepares mode and SQLite's driver both silently
 * accept `WHERE a = :x AND b = :x` with a single `execute(['x' => ...])`
 * call, but MySQL/PostgreSQL native prepares (the default when a
 * consumer sets `PDO::ATTR_EMULATE_PREPARES = false` to harden their
 * config) reject it with `SQLSTATE[HY093] Invalid parameter number`.
 *
 * Because core's integration tests run against SQLite, that whole class
 * of bug is invisible. This helper closes the gap: tests wire it in
 * place of the default `PDO` and any repository that reuses a named
 * placeholder is caught at `prepare()` time, independent of the DB
 * driver. See also
 * `tests/Integration/Persistence/RepositorySqlPortabilityTest.php`.
 *
 * The parser is intentionally simple — core's repositories don't
 * construct SQL with string literals containing `:` characters, so a
 * naive scan for `:name` tokens is sufficient. If that ever changes,
 * upgrade the parser here rather than disabling the check.
 */
final class StrictPlaceholderPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->assertNoReusedNamedPlaceholder($query);
        return parent::prepare($query, $options);
    }

    private function assertNoReusedNamedPlaceholder(string $sql): void
    {
        if (preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $matches) === false) {
            return;
        }

        $counts = array_count_values($matches[1]);
        $reused = array_filter($counts, static fn (int $c): bool => $c > 1);

        if ($reused === []) {
            return;
        }

        $names = array_map(
            static fn (string $name, int $count): string => ":{$name} ({$count} uses)",
            array_keys($reused),
            array_values($reused),
        );

        throw new RuntimeException(sprintf(
            "Named placeholder reused in prepared statement: %s.\n"
            . "This works in PDO emulated mode and SQLite but breaks on "
            . "MySQL/PostgreSQL native prepares with SQLSTATE[HY093]. "
            . "Split each placeholder into a unique name and bind each "
            . "copy explicitly.\nSQL:\n%s",
            implode(', ', $names),
            $sql,
        ));
    }
}
