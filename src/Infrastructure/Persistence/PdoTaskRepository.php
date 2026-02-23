<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\Task;
use WebCalendar\Core\Domain\Repository\TaskRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\RecurrenceRule;
use WebCalendar\Core\Domain\ValueObject\ExDate;
use WebCalendar\Core\Domain\ValueObject\RDate;

/**
 * PDO-based implementation of TaskRepositoryInterface.
 */
final readonly class PdoTaskRepository implements TaskRepositoryInterface
{
    use TransactionalTrait;
    public function __construct(
        private PDO $pdo,
        private string $tablePrefix = '',
    ) {
    }

    public function findById(EventId $id): ?Task
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tablePrefix}webcal_entry WHERE cal_id = :id AND cal_type IN ('T', 'N')");
        $stmt->execute(['id' => $id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowToTask($row);
    }

    /**
     * @return Task[]
     */
    public function findByDateRange(DateRange $range, ?string $user = null): array
    {
        $sql = "SELECT * FROM {$this->tablePrefix}webcal_entry
                WHERE cal_date BETWEEN :start AND :end AND cal_type IN ('T', 'N')";
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
        $tasks = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $tasks[] = $this->mapRowToTask($row);
            }
        }

        return $tasks;
    }

    public function save(Task $task): void
    {
        $idValue = $task->id()->value();
        $isNew = ($idValue === 0);

        $this->executeInTransaction(function () use ($task, &$idValue, $isNew): void {
            if ($isNew) {
                $idValue = $this->getNextId();
            }

            $data = [
                'id' => $idValue,
                'create_by' => $task->createdBy(),
                'date' => (int)$task->start()->format('Ymd'),
                'time' => (int)$task->start()->format('His'),
                'duration' => $task->duration(),
                'due_date' => $task->dueDate()?->format('Ymd'),
                'due_time' => $task->dueDate()?->format('His'),
                'name' => $task->name(),
                'description' => $task->description(),
                'location' => $task->location(),
                'type' => $task->type()->value,
                'access' => $task->access()->value,
                'uid' => $task->uid(),
                'sequence' => $task->sequence(),
                'status' => $task->status()
            ];

            if ($isNew) {
                $sql = "INSERT INTO {$this->tablePrefix}webcal_entry
                        (cal_id, cal_create_by, cal_date, cal_time, cal_duration,
                         cal_due_date, cal_due_time, cal_name,
                         cal_description, cal_location, cal_type, cal_access, cal_uid,
                         cal_sequence, cal_status)
                        VALUES (:id, :create_by, :date, :time, :duration,
                                :due_date, :due_time, :name,
                                :description, :location, :type, :access, :uid,
                                :sequence, :status)";
            } else {
                $sql = "UPDATE {$this->tablePrefix}webcal_entry SET
                        cal_create_by = :create_by,
                        cal_date = :date,
                        cal_time = :time,
                        cal_duration = :duration,
                        cal_due_date = :due_date,
                        cal_due_time = :due_time,
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
            
            $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->tablePrefix}webcal_entry_user WHERE cal_id = :id AND cal_login = :login");
            $stmt->execute(['id' => $idValue, 'login' => $task->createdBy()]);
            if ($stmt->fetch()) {
                $this->pdo->prepare("UPDATE {$this->tablePrefix}webcal_entry_user SET cal_percent = :percent WHERE cal_id = :id AND cal_login = :login")
                    ->execute(['id' => $idValue, 'login' => $task->createdBy(), 'percent' => $task->percentComplete()]);
            } else {
                $this->pdo->prepare("INSERT INTO {$this->tablePrefix}webcal_entry_user (cal_id, cal_login, cal_percent) VALUES (:id, :login, :percent)")
                    ->execute(['id' => $idValue, 'login' => $task->createdBy(), 'percent' => $task->percentComplete()]);
            }
        });
    }

    public function delete(EventId $id): void
    {
        $idValue = $id->value();
        
        $this->executeInTransaction(function () use ($idValue): void {
            $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_entry_user WHERE cal_id = :id")
                ->execute(['id' => $idValue]);
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
    private function mapRowToTask(array $row): Task
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

        $due = null;
        if (!empty($row['cal_due_date'])) {
            $rawDueDate = $row['cal_due_date'];
            $rawDueTime = $row['cal_due_time'] ?? '0';
            $dueDateStr = is_scalar($rawDueDate) ? (string)$rawDueDate : '';
            $dueTimeStr = is_scalar($rawDueTime) ? (string)$rawDueTime : '0';
            $dueStr = $dueDateStr . str_pad($dueTimeStr, 6, '0', STR_PAD_LEFT);
            $dueParsed = \DateTimeImmutable::createFromFormat('YmdHis', $dueStr);
            $due = $dueParsed !== false ? $dueParsed : null;
        }

        $id = is_numeric($row['cal_id'] ?? null) ? (int)$row['cal_id'] : 0;
        $uid = is_string($row['cal_uid'] ?? null) ? $row['cal_uid'] : '';
        $name = is_string($row['cal_name'] ?? null) ? $row['cal_name'] : '';
        $description = is_string($row['cal_description'] ?? null) ? $row['cal_description'] : '';
        $location = is_string($row['cal_location'] ?? null) ? $row['cal_location'] : '';
        $duration = is_numeric($row['cal_duration'] ?? null) ? (int)$row['cal_duration'] : 0;
        $createBy = is_string($row['cal_create_by'] ?? null) ? $row['cal_create_by'] : '';
        $type = is_string($row['cal_type'] ?? null) ? $row['cal_type'] : 'T';
        $access = is_string($row['cal_access'] ?? null) ? $row['cal_access'] : 'P';
        $sequence = is_numeric($row['cal_sequence'] ?? null) ? (int)$row['cal_sequence'] : 0;
        $status = is_string($row['cal_status'] ?? null) ? $row['cal_status'] : null;

        // Get percent from creator
        $stmt = $this->pdo->prepare("SELECT cal_percent FROM {$this->tablePrefix}webcal_entry_user WHERE cal_id = :id AND cal_login = :login");
        $stmt->execute(['id' => $id, 'login' => $createBy]);
        $rawPercent = $stmt->fetchColumn();
        $percent = is_numeric($rawPercent) ? (int)$rawPercent : 0;

        return new Task(
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
            dueDate: $due,
            percentComplete: $percent,
            recurrence: new Recurrence(), // Could load this too
            sequence: $sequence,
            status: $status
        );
    }

}
