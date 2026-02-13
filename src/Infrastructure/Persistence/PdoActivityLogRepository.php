<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\ActivityLogEntry;
use WebCalendar\Core\Domain\Repository\ActivityLogRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;
use WebCalendar\Core\Domain\ValueObject\DateRange;

/**
 * PDO-based implementation of ActivityLogRepositoryInterface.
 */
final readonly class PdoActivityLogRepository implements ActivityLogRepositoryInterface
{
    public function __construct(
        private PDO $pdo,
        private string $tablePrefix = '',
    ) {
    }

    public function save(ActivityLogEntry $entry): void
    {
        $data = [
            'entry_id' => $entry->entryId(),
            'login' => $entry->login(),
            'user_cal' => $entry->userCal(),
            'type' => $entry->type()->value,
            'date' => (int)$entry->date()->format('Ymd'),
            'time' => (int)$entry->date()->format('His'),
            'text' => $entry->text()
        ];

        // For activity logs, we always INSERT, never UPDATE.
        // Legacy table uses cal_log_id as PK, usually auto-increment.
        // sqlite-schema.sql: cal_log_id INT NOT NULL, PRIMARY KEY (cal_log_id)
        // Wait, I need to check if it's auto-increment in schema.
        
        $sql = "INSERT INTO {$this->tablePrefix}webcal_entry_log (cal_entry_id, cal_login, cal_user_cal, cal_type, cal_date, cal_time, cal_text)
                VALUES (:entry_id, :login, :user_cal, :type, :date, :time, :text)";

        $this->pdo->prepare($sql)->execute($data);
    }

    /**
     * @return ActivityLogEntry[]
     */
    public function findByDateRange(DateRange $range, ?string $login = null): array
    {
        $sql = "SELECT * FROM {$this->tablePrefix}webcal_entry_log WHERE cal_date BETWEEN :start AND :end";
        $params = [
            'start' => (int)$range->startDate()->format('Ymd'),
            'end' => (int)$range->endDate()->format('Ymd')
        ];

        if ($login !== null) {
            $sql .= ' AND cal_login = :login';
            $params['login'] = $login;
        }

        $sql .= ' ORDER BY cal_date DESC, cal_time DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $entries = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $entries[] = $this->mapRowToEntry($row);
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToEntry(array $row): ActivityLogEntry
    {
        $rawDate = $row['cal_date'] ?? '';
        $dateStr = is_scalar($rawDate) ? (string)$rawDate : '';
        
        $rawTime = $row['cal_time'] ?? 0;
        $timeInt = is_numeric($rawTime) ? (int)$rawTime : 0;
        $timeStr = str_pad((string)$timeInt, 6, '0', STR_PAD_LEFT);
        
        $date = \DateTimeImmutable::createFromFormat('YmdHis', $dateStr . $timeStr);
        if ($date === false) {
            $date = new \DateTimeImmutable($dateStr !== '' ? $dateStr : 'now');
        }

        return new ActivityLogEntry(
            id: is_numeric($row['cal_log_id'] ?? null) ? (int)$row['cal_log_id'] : 0,
            entryId: is_numeric($row['cal_entry_id'] ?? null) ? (int)$row['cal_entry_id'] : 0,
            login: is_string($row['cal_login'] ?? null) ? $row['cal_login'] : '',
            userCal: is_string($row['cal_user_cal'] ?? null) ? $row['cal_user_cal'] : null,
            type: ActivityLogType::from(is_string($row['cal_type'] ?? null) ? $row['cal_type'] : 'C'),
            date: $date,
            text: is_string($row['cal_text'] ?? null) ? $row['cal_text'] : ''
        );
    }
}
