<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use WebCalendar\Core\Domain\ValueObject\OrderStatus;

/**
 * Deletes a webcal_entry row together with every row keyed by its cal_id.
 *
 * Entry ids are allocated as MAX(cal_id)+1, so a deleted entry's id is
 * reissued to the next entry anyone creates. None of these tables has a
 * foreign key, so any row left behind would attach to that new entry and
 * expose the previous owner's attachments, extras, reminders or ticket
 * buyers to whoever created it.
 *
 * Ticket orders are money records, so an entry with a PENDING order, or a
 * PAID order that took money, is not deleted: cancel or refund first. A
 * free PAID order (an RSVP) holds no money and goes with the entry.
 *
 * Hosts may install only part of the schema (the WordPress plugin has no
 * webcal_blob or webcal_entry_ext_user), so dependent tables the database
 * lacks are skipped. They are looked up before any DELETE runs: a failed
 * statement would abort the caller's transaction on PostgreSQL.
 *
 * Requires the using class to have `PDO $pdo` and `string $tablePrefix`
 * properties. Callers run it inside their own transaction.
 */
trait DeletesEntryRowsTrait
{
    /** @var list<string>|null Dependent tables present in the database. */
    private ?array $presentEntryTables = null;

    /**
     * Tables holding per-entry rows keyed by cal_id. A method, not a
     * constant, because traits cannot declare constants before PHP 8.2.
     *
     * @return list<string>
     */
    private static function entryDependentTables(): array
    {
        return [
            'webcal_entry_user',
            'webcal_entry_repeats',
            'webcal_entry_repeats_not',
            'webcal_entry_categories',
            'webcal_entry_ext_user',
            'webcal_site_extras',
            'webcal_reminders',
            'webcal_blob',
            'webcal_import_data',
            'webcal_attendee',
            'webcal_ticket_order',
            'webcal_ticket_type',
        ];
    }

    /**
     * @throws \DomainException if the entry still has a PENDING order or a
     *         PAID order with a non-zero amount
     */
    private function deleteEntryRows(int $calId): void
    {
        $tables = $this->presentEntryTables();
        if (in_array('webcal_ticket_order', $tables, true)) {
            $this->refuseIfEntryHasLiveOrders($calId);
        }

        foreach ([...$tables, 'webcal_entry'] as $table) {
            $this->pdo->prepare(
                "DELETE FROM {$this->tablePrefix}{$table} WHERE cal_id = :id"
            )->execute(['id' => $calId]);
        }
    }

    /**
     * @throws \DomainException if the entry has a PENDING order or a PAID
     *         order with a non-zero amount
     */
    private function refuseIfEntryHasLiveOrders(int $calId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$this->tablePrefix}webcal_ticket_order
              WHERE cal_id = ?
                AND (order_status = ? OR (order_status = ? AND order_amount > 0))"
        );
        $stmt->execute([$calId, OrderStatus::PENDING->value, OrderStatus::PAID->value]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new \DomainException(sprintf(
                'Entry %d has active ticket orders; cancel or refund them before deleting it.',
                $calId
            ));
        }
    }

    /**
     * The dependent tables that exist, without the prefix. On a driver this
     * cannot inspect, every table is assumed present.
     *
     * @return list<string>
     */
    private function presentEntryTables(): array
    {
        if ($this->presentEntryTables !== null) {
            return $this->presentEntryTables;
        }

        $wanted = array_map(
            fn (string $table): string => strtolower($this->tablePrefix . $table),
            self::entryDependentTables()
        );
        $placeholders = implode(', ', array_fill(0, count($wanted), '?'));
        $sql = match ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => "SELECT name FROM sqlite_master
                          WHERE type = 'table' AND LOWER(name) IN ($placeholders)",
            'mysql' => "SELECT table_name FROM information_schema.tables
                         WHERE table_schema = DATABASE()
                           AND LOWER(table_name) IN ($placeholders)",
            'pgsql' => "SELECT table_name FROM information_schema.tables
                         WHERE table_schema = ANY (current_schemas(false))
                           AND LOWER(table_name) IN ($placeholders)",
            default => null,
        };
        if ($sql === null) {
            return $this->presentEntryTables = self::entryDependentTables();
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($wanted);
        $found = array_map(
            static fn (mixed $name): string => is_string($name) ? strtolower($name) : '',
            $stmt->fetchAll(\PDO::FETCH_COLUMN)
        );

        // Keep the declared order so deletes run the same way everywhere.
        return $this->presentEntryTables = array_values(array_filter(
            self::entryDependentTables(),
            fn (string $table): bool => in_array(
                strtolower($this->tablePrefix . $table),
                $found,
                true
            )
        ));
    }
}
