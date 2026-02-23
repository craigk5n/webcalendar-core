<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\Journal;
use WebCalendar\Core\Domain\Repository\JournalRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\Recurrence;

/**
 * PDO-based implementation of JournalRepositoryInterface.
 */
final readonly class PdoJournalRepository implements JournalRepositoryInterface
{
    public function __construct(
        private PDO $pdo,
        private string $tablePrefix = '',
    ) {
    }

    public function findById(EventId $id): ?Journal
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tablePrefix}webcal_entry WHERE cal_id = :id AND cal_type IN ('J', 'O')");
        $stmt->execute(['id' => $id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowToJournal($row);
    }

    /**
     * @return Journal[]
     */
    public function findByDateRange(DateRange $range, ?string $user = null): array
    {
        $sql = "SELECT * FROM {$this->tablePrefix}webcal_entry
                WHERE cal_date BETWEEN :start AND :end AND cal_type IN ('J', 'O')";
        $params = [
            'start' => (int)$range->startDate()->format('Ymd'),
            'end' => (int)$range->endDate()->format('Ymd')
        ];

        if ($user !== null) {
            $sql .= ' AND cal_create_by = :login';
            $params['login'] = $user;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $journals = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $journals[] = $this->mapRowToJournal($row);
            }
        }

        return $journals;
    }

    public function save(Journal $journal): void
    {
        $idValue = $journal->id()->value();
        $isNew = ($idValue === 0);

        $this->executeInTransaction(function () use ($journal, &$idValue, $isNew): void {
            if ($isNew) {
                $idValue = $this->getNextId();
            }

            $data = [
                'id' => $idValue,
                'create_by' => $journal->createdBy(),
                'date' => (int)$journal->start()->format('Ymd'),
                'time' => (int)$journal->start()->format('His'),
                'duration' => $journal->duration(),
                'name' => $journal->name(),
                'description' => $journal->description(),
                'location' => $journal->location(),
                'type' => $journal->type()->value,
                'access' => $journal->access()->value,
                'uid' => $journal->uid(),
                'sequence' => $journal->sequence(),
                'status' => $journal->status()
            ];

            if ($isNew) {
                $sql = "INSERT INTO {$this->tablePrefix}webcal_entry
                        (cal_id, cal_create_by, cal_date, cal_time, cal_duration, cal_name,
                         cal_description, cal_location, cal_type, cal_access, cal_uid,
                         cal_sequence, cal_status)
                        VALUES (:id, :create_by, :date, :time, :duration, :name,
                                :description, :location, :type, :access, :uid,
                                :sequence, :status)";
            } else {
                $sql = "UPDATE {$this->tablePrefix}webcal_entry SET
                        cal_create_by = :create_by,
                        cal_date = :date,
                        cal_time = :time,
                        cal_duration = :duration,
                        cal_name = :name,
                        cal_description = :description,
                        cal_location = :location,
                        cal_type = :type,
                        cal_access = :access,
                        cal_uid = :uid,
                        cal_sequence = :sequence,
                        cal_status = :status
                        WHERE cal_id = :id";
            }

            $this->pdo->prepare($sql)->execute($data);
        });
    }

    public function delete(EventId $id): void
    {
        $idValue = $id->value();
        
        $this->executeInTransaction(function () use ($idValue): void {
            $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_entry WHERE cal_id = :id")
                ->execute(['id' => $idValue]);
        });
    }

    private function getNextId(): int
    {
        $stmt = $this->pdo->query("SELECT IFNULL(MAX(cal_id), 0) + 1 FROM {$this->tablePrefix}webcal_entry");
        if ($stmt === false) {
            return 1;
        }
        $val = $stmt->fetchColumn();
        return is_numeric($val) ? (int)$val : 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToJournal(array $row): Journal
    {
        $rawDate = $row['cal_date'] ?? '';
        $dateStr = is_scalar($rawDate) ? (string)$rawDate : '';
        
        $rawTime = $row['cal_time'] ?? 0;
        $timeInt = is_numeric($rawTime) ? (int)$rawTime : 0;
        $timeStr = str_pad((string)$timeInt, 6, '0', STR_PAD_LEFT);
        
        $start = \DateTimeImmutable::createFromFormat('YmdHis', $dateStr . $timeStr);
        if ($start === false) {
            $start = new \DateTimeImmutable($dateStr !== '' ? $dateStr : 'now');
        }

        $id = is_numeric($row['cal_id'] ?? null) ? (int)$row['cal_id'] : 0;
        $uid = is_string($row['cal_uid'] ?? null) ? $row['cal_uid'] : '';
        $name = is_string($row['cal_name'] ?? null) ? $row['cal_name'] : '';
        $description = is_string($row['cal_description'] ?? null) ? $row['cal_description'] : '';
        $location = is_string($row['cal_location'] ?? null) ? $row['cal_location'] : '';
        $duration = is_numeric($row['cal_duration'] ?? null) ? (int)$row['cal_duration'] : 0;
        $createBy = is_string($row['cal_create_by'] ?? null) ? $row['cal_create_by'] : '';
        $type = is_string($row['cal_type'] ?? null) ? $row['cal_type'] : 'J';
        $access = is_string($row['cal_access'] ?? null) ? $row['cal_access'] : 'P';
        $sequence = is_numeric($row['cal_sequence'] ?? null) ? (int)$row['cal_sequence'] : 0;
        $status = is_string($row['cal_status'] ?? null) ? $row['cal_status'] : null;

        return new Journal(
            id: new EventId($id),
            uid: $uid,
            name: $name,
            description: $description,
            location: $location,
            start: $start,
            duration: $duration,
            createdBy: $createBy,
            type: EventType::from($type),
            access: AccessLevel::from($access),
            recurrence: new Recurrence(),
            sequence: $sequence,
            status: $status
        );
    }

    private function executeInTransaction(callable $callback): void
    {
        $inTransaction = $this->pdo->inTransaction();
        
        if (!$inTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $callback();
            if (!$inTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!$inTransaction) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
