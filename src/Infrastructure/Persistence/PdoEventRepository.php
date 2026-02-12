<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\Recurrence;

/**
 * PDO-based implementation of EventRepositoryInterface.
 */
final readonly class PdoEventRepository implements EventRepositoryInterface
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findById(EventId $id): ?Event
    {
        $stmt = $this->pdo->prepare('SELECT * FROM webcal_entry WHERE cal_id = :id');
        $stmt->execute(['id' => $id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowToEvent($row);
    }

    public function findByUid(string $uid): ?Event
    {
        $stmt = $this->pdo->prepare('SELECT * FROM webcal_entry WHERE cal_uid = :uid');
        $stmt->execute(['uid' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowToEvent($row);
    }

    /**
     * @return Event[]
     */
    public function findByDateRange(DateRange $range, ?User $user = null): array
    {
        $startDateInt = (int)$range->startDate()->format('Ymd');
        $endDateInt = (int)$range->endDate()->format('Ymd');

        $sql = 'SELECT * FROM webcal_entry 
                WHERE cal_date BETWEEN :start AND :end';
        $params = [
            'start' => $startDateInt,
            'end' => $endDateInt
        ];

        if ($user !== null) {
            $sql .= ' AND cal_create_by = :login';
            $params['login'] = $user->login();
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $events = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $events[] = $this->mapRowToEvent($row);
            }
        }

        return $events;
    }

    public function save(Event $event): void
    {
        $data = [
            'id' => $event->id()->value(),
            'create_by' => $event->createdBy(),
            'date' => (int)$event->start()->format('Ymd'),
            'time' => (int)$event->start()->format('His'),
            'duration' => $event->duration(),
            'name' => $event->name(),
            'description' => $event->description(),
            'location' => $event->location(),
            'type' => $event->type()->value,
            'access' => $event->access()->value,
            'uid' => $event->uid(),
            'sequence' => $event->sequence(),
            'status' => $event->status()
        ];

        if ($data['id'] === 0) {
            $data['id'] = $this->getNextId();
        }

        $sql = 'INSERT OR REPLACE INTO webcal_entry 
                (cal_id, cal_create_by, cal_date, cal_time, cal_duration, cal_name, 
                 cal_description, cal_location, cal_type, cal_access, cal_uid, 
                 cal_sequence, cal_status)
                VALUES (:id, :create_by, :date, :time, :duration, :name, 
                        :description, :location, :type, :access, :uid, 
                        :sequence, :status)';

        $this->pdo->prepare($sql)->execute($data);
        
        $this->saveRecurrence((int)$data['id'], $event->recurrence());
    }

    public function delete(EventId $id): void
    {
        $this->pdo->prepare('DELETE FROM webcal_entry WHERE cal_id = :id')
            ->execute(['id' => $id->value()]);
        $this->pdo->prepare('DELETE FROM webcal_entry_repeats WHERE cal_id = :id')
            ->execute(['id' => $id->value()]);
    }

    private function getNextId(): int
    {
        $stmt = $this->pdo->query('SELECT IFNULL(MAX(cal_id), 0) + 1 FROM webcal_entry');
        if ($stmt === false) {
            return 1;
        }
        $val = $stmt->fetchColumn();
        return is_numeric($val) ? (int)$val : 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToEvent(array $row): Event
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
        $type = is_string($row['cal_type'] ?? null) ? $row['cal_type'] : 'E';
        $access = is_string($row['cal_access'] ?? null) ? $row['cal_access'] : 'P';
        $sequence = is_numeric($row['cal_sequence'] ?? null) ? (int)$row['cal_sequence'] : 0;
        $status = is_string($row['cal_status'] ?? null) ? $row['cal_status'] : null;

        $recurrence = $this->loadRecurrence($id);

        return new Event(
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
            recurrence: $recurrence,
            sequence: $sequence,
            status: $status
        );
    }

    private function loadRecurrence(int $id): Recurrence
    {
        return new Recurrence();
    }

    private function saveRecurrence(int $id, Recurrence $recurrence): void
    {
    }
}
