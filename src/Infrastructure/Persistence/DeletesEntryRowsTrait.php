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
 * Ticket orders are money records, so an entry with PENDING or PAID orders
 * is not deleted: they must be cancelled or refunded first.
 *
 * Requires the using class to have `PDO $pdo` and `string $tablePrefix`
 * properties. Callers run it inside their own transaction.
 */
trait DeletesEntryRowsTrait
{
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
     * @throws \DomainException if the entry still has PENDING or PAID orders
     */
    private function deleteEntryRows(int $calId): void
    {
        $live = array_map(
            static fn (OrderStatus $status): string => $status->value,
            OrderStatus::capacityHolding()
        );
        $placeholders = implode(', ', array_fill(0, count($live), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$this->tablePrefix}webcal_ticket_order
              WHERE cal_id = ? AND order_status IN ($placeholders)"
        );
        $stmt->execute([$calId, ...$live]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new \DomainException(sprintf(
                'Entry %d has active ticket orders; cancel or refund them before deleting it.',
                $calId
            ));
        }

        foreach ([...self::entryDependentTables(), 'webcal_entry'] as $table) {
            $this->pdo->prepare(
                "DELETE FROM {$this->tablePrefix}{$table} WHERE cal_id = :id"
            )->execute(['id' => $calId]);
        }
    }
}
