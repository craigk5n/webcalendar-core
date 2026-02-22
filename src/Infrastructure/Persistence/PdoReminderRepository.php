<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\Reminder;
use WebCalendar\Core\Domain\Repository\ReminderRepositoryInterface;

/**
 * PDO implementation of the ReminderRepositoryInterface.
 */
final readonly class PdoReminderRepository implements ReminderRepositoryInterface
{
    public function __construct(
        private PDO $pdo,
        private string $tablePrefix = '',
    ) {
    }

    public function findPending(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.*, e.cal_name, e.cal_description, e.cal_location, e.cal_date, e.cal_time
             FROM {$this->tablePrefix}webcal_reminders r
             JOIN {$this->tablePrefix}webcal_entry e ON r.cal_id = e.cal_id
             WHERE r.cal_last_sent = 0"
        );
        $stmt->execute();

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $results[] = [
                'reminder' => $this->mapRowToReminder($row),
                'event_name' => is_string($row['cal_name'] ?? null) ? $row['cal_name'] : '',
                'event_description' => is_string($row['cal_description'] ?? null) ? $row['cal_description'] : '',
                'event_location' => is_string($row['cal_location'] ?? null) ? $row['cal_location'] : '',
                'event_date' => is_numeric($row['cal_date'] ?? null) ? (int)$row['cal_date'] : 0,
                'event_time' => is_numeric($row['cal_time'] ?? null) ? (int)$row['cal_time'] : 0,
            ];
        }

        return $results;
    }

    public function markSent(int $eventId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->tablePrefix}webcal_reminders SET cal_last_sent = :timestamp WHERE cal_id = :id"
        );
        $stmt->execute(['timestamp' => time(), 'id' => $eventId]);
    }

    public function save(Reminder $reminder): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM {$this->tablePrefix}webcal_reminders WHERE cal_id = :id"
        );
        $stmt->execute(['id' => $reminder->eventId()]);

        $data = [
            'id' => $reminder->eventId(),
            'date' => $reminder->date(),
            'offset' => $reminder->offset(),
            'related' => $reminder->related(),
            'before' => $reminder->before(),
            'last_sent' => $reminder->lastSent(),
            'repeats' => $reminder->repeats(),
            'duration' => $reminder->duration(),
            'times_sent' => $reminder->timesSent(),
            'action' => $reminder->action(),
        ];

        if ($stmt->fetch()) {
            $sql = "UPDATE {$this->tablePrefix}webcal_reminders SET
                    cal_date = :date, cal_offset = :offset, cal_related = :related,
                    cal_before = :before, cal_last_sent = :last_sent, cal_repeats = :repeats,
                    cal_duration = :duration, cal_times_sent = :times_sent, cal_action = :action
                    WHERE cal_id = :id";
        } else {
            $sql = "INSERT INTO {$this->tablePrefix}webcal_reminders
                    (cal_id, cal_date, cal_offset, cal_related, cal_before, cal_last_sent,
                     cal_repeats, cal_duration, cal_times_sent, cal_action)
                    VALUES (:id, :date, :offset, :related, :before, :last_sent,
                            :repeats, :duration, :times_sent, :action)";
        }

        $this->pdo->prepare($sql)->execute($data);
    }

    public function delete(int $eventId): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM {$this->tablePrefix}webcal_reminders WHERE cal_id = :id"
        );
        $stmt->execute(['id' => $eventId]);
    }

    /** @param array<string, mixed> $row */
    private function mapRowToReminder(array $row): Reminder
    {
        return new Reminder(
            eventId: is_numeric($row['cal_id'] ?? null) ? (int)$row['cal_id'] : 0,
            date: is_numeric($row['cal_date'] ?? null) ? (int)$row['cal_date'] : 0,
            offset: is_numeric($row['cal_offset'] ?? null) ? (int)$row['cal_offset'] : 0,
            related: is_string($row['cal_related'] ?? null) ? $row['cal_related'] : 'S',
            before: is_string($row['cal_before'] ?? null) ? $row['cal_before'] : 'Y',
            lastSent: is_numeric($row['cal_last_sent'] ?? null) ? (int)$row['cal_last_sent'] : 0,
            repeats: is_numeric($row['cal_repeats'] ?? null) ? (int)$row['cal_repeats'] : 0,
            duration: is_numeric($row['cal_duration'] ?? null) ? (int)$row['cal_duration'] : 0,
            timesSent: is_numeric($row['cal_times_sent'] ?? null) ? (int)$row['cal_times_sent'] : 0,
            action: is_string($row['cal_action'] ?? null) ? $row['cal_action'] : 'EMAIL',
        );
    }
}
