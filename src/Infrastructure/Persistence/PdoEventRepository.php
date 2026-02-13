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
use WebCalendar\Core\Domain\ValueObject\RecurrenceRule;
use WebCalendar\Core\Domain\ValueObject\ExDate;
use WebCalendar\Core\Domain\ValueObject\RDate;

/**
 * PDO-based implementation of EventRepositoryInterface.
 */
final readonly class PdoEventRepository implements EventRepositoryInterface
{
    public function __construct(
        private PDO $pdo,
        private string $tablePrefix = '',
    ) {
    }

    public function findById(EventId $id): ?Event
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tablePrefix}webcal_entry WHERE cal_id = :id");
        $stmt->execute(['id' => $id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowToEvent($row);
    }

    public function findByUid(string $uid): ?Event
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tablePrefix}webcal_entry WHERE cal_uid = :uid");
        $stmt->execute(['uid' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowToEvent($row);
    }

    public function search(string $keyword, ?DateRange $range = null, ?User $user = null): \WebCalendar\Core\Domain\ValueObject\EventCollection
    {
        $sql = "SELECT * FROM {$this->tablePrefix}webcal_entry WHERE (cal_name LIKE :keyword OR cal_description LIKE :keyword)";
        $params = ['keyword' => '%' . $keyword . '%'];

        if ($range !== null) {
            $sql .= ' AND cal_date BETWEEN :start AND :end';
            $params['start'] = (int)$range->startDate()->format('Ymd');
            $params['end'] = (int)$range->endDate()->format('Ymd');
        }

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

        return new \WebCalendar\Core\Domain\ValueObject\EventCollection($events);
    }

    /**
     * @return Event[]
     */
    public function findByDateRange(DateRange $range, ?User $user = null): array
    {
        $startDateInt = (int)$range->startDate()->format('Ymd');
        $endDateInt = (int)$range->endDate()->format('Ymd');

        $sql = "SELECT * FROM {$this->tablePrefix}webcal_entry
                WHERE cal_date BETWEEN :start AND :end";
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
        $idValue = $event->id()->value();
        $isNew = ($idValue === 0);

        if ($isNew) {
            $idValue = $this->getNextId();
        }

        $data = [
            'id' => $idValue,
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
        
        $this->saveRecurrence($idValue, $event->recurrence());
    }

    public function delete(EventId $id): void
    {
        $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_entry WHERE cal_id = :id")
            ->execute(['id' => $id->value()]);
        $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_entry_repeats WHERE cal_id = :id")
            ->execute(['id' => $id->value()]);
    }

    public function updateParticipantStatus(EventId $eventId, string $userLogin, string $status): void
    {
        $sql = "UPDATE {$this->tablePrefix}webcal_entry_user SET cal_status = :status
                WHERE cal_id = :id AND cal_login = :login";
        
        $this->pdo->prepare($sql)->execute([
            'id' => $eventId->value(),
            'login' => $userLogin,
            'status' => $status
        ]);
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
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tablePrefix}webcal_entry_repeats WHERE cal_id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $rule = null;
        if (is_array($row)) {
            $rule = $this->mapRowToRecurrenceRule($row);
        }

        [$exDate, $rDate] = $this->loadExceptions($id);

        return new Recurrence($rule, $exDate, $rDate);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToRecurrenceRule(array $row): RecurrenceRule
    {
        $parts = [];
        
        $type = is_string($row['cal_type'] ?? null) ? $row['cal_type'] : 'daily';
        $parts['FREQ'] = match ($type) {
            'daily' => 'DAILY',
            'weekly' => 'WEEKLY',
            'monthlyByDate', 'monthlyByDay', 'monthlyBySetPos' => 'MONTHLY',
            'yearly' => 'YEARLY',
            default => 'DAILY'
        };

        $frequency = is_numeric($row['cal_frequency'] ?? null) ? (int)$row['cal_frequency'] : 1;
        if ($frequency > 1) {
            $parts['INTERVAL'] = $frequency;
        }

        $count = is_numeric($row['cal_count'] ?? null) ? (int)$row['cal_count'] : 0;
        if ($count > 0) {
            $parts['COUNT'] = $count;
        }

        $end = is_scalar($row['cal_end'] ?? null) ? (string)$row['cal_end'] : '';
        if (!empty($end)) {
            $endTime = is_numeric($row['cal_endtime'] ?? null) ? (int)$row['cal_endtime'] : 0;
            $time = str_pad((string)$endTime, 6, '0', STR_PAD_LEFT);
            $parts['UNTIL'] = $end . 'T' . $time . 'Z';
        }

        $byDay = is_string($row['cal_byday'] ?? null) ? $row['cal_byday'] : '';
        if (!empty($byDay)) {
            $parts['BYDAY'] = $byDay;
        }

        $byMonth = is_string($row['cal_bymonth'] ?? null) ? $row['cal_bymonth'] : '';
        if (!empty($byMonth)) {
            $parts['BYMONTH'] = $byMonth;
        }

        $byMonthDay = is_string($row['cal_bymonthday'] ?? null) ? $row['cal_bymonthday'] : '';
        if (!empty($byMonthDay)) {
            $parts['BYMONTHDAY'] = $byMonthDay;
        }

        $bySetPos = is_string($row['cal_bysetpos'] ?? null) ? $row['cal_bysetpos'] : '';
        if (!empty($bySetPos)) {
            $parts['BYSETPOS'] = $bySetPos;
        }

        $wkst = is_string($row['cal_wkst'] ?? null) ? $row['cal_wkst'] : '';
        if (!empty($wkst)) {
            $parts['WKST'] = $wkst;
        }

        return RecurrenceRule::fromParts($parts);
    }

    /**
     * @return array{\WebCalendar\Core\Domain\ValueObject\ExDate, \WebCalendar\Core\Domain\ValueObject\RDate}
     */
    private function loadExceptions(int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT cal_date, cal_exdate FROM {$this->tablePrefix}webcal_entry_repeats_not WHERE cal_id = :id");
        $stmt->execute(['id' => $id]);
        
        $exDates = [];
        $rDates = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $dateStr = (string)$row['cal_date'];
                $date = \DateTimeImmutable::createFromFormat('Ymd', $dateStr);
                if ($date !== false) {
                    if ((int)$row['cal_exdate'] === 1) {
                        $exDates[] = $date;
                    } else {
                        $rDates[] = $date;
                    }
                }
            }
        }

        return [
            new \WebCalendar\Core\Domain\ValueObject\ExDate($exDates),
            new \WebCalendar\Core\Domain\ValueObject\RDate($rDates)
        ];
    }

    private function saveRecurrence(int $id, Recurrence $recurrence): void
    {
        // 1. Delete existing rules and exceptions
        $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_entry_repeats WHERE cal_id = :id")
            ->execute(['id' => $id]);
        $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_entry_repeats_not WHERE cal_id = :id")
            ->execute(['id' => $id]);

        $rule = $recurrence->rule();
        if ($rule === null) {
            // Check if RDATEs exist (PRD says RDATE additions include extra dates)
            $this->saveExceptions($id, $recurrence);
            return;
        }

        $rrule = $rule->getRRule();
        
        // 2. Map RRULE to legacy columns
        $data = [
            'id' => $id,
            'type' => $this->mapFreqToType($rrule->getFreq()),
            'end' => $rrule->getUntil()?->format('Ymd'),
            'endtime' => $rrule->getUntil()?->format('His'),
            'frequency' => $rrule->getInterval(),
            'count' => $rrule->getCount(),
            'wkst' => $rrule->getWkst(),
            'bymonth' => $this->implodeOrNull($rrule->getByMonth()),
            'bymonthday' => $this->implodeOrNull($rrule->getByMonthDay()),
            'byyearday' => $this->implodeOrNull($rrule->getByYearDay()),
            'byweekno' => $this->implodeOrNull($rrule->getByWeekNo()),
            'bysetpos' => $this->implodeOrNull($rrule->getBySetPos()),
            'byhour' => $this->implodeOrNull($rrule->getByHour()),
            'byminute' => $this->implodeOrNull($rrule->getByMinute()),
            'bysecond' => $this->implodeOrNull($rrule->getBySecond()),
            'days' => $this->mapByDayToDaysBitmask($rrule->getByDay(), $rrule->getFreq()),
            'byday' => $this->mapByDayToLegacyString($rrule->getByDay())
        ];

        $sql = "INSERT INTO {$this->tablePrefix}webcal_entry_repeats (
                    cal_id, cal_type, cal_end, cal_endtime, cal_frequency, cal_count,
                    cal_wkst, cal_bymonth, cal_bymonthday, cal_byyearday, cal_byweekno,
                    cal_bysetpos, cal_byhour, cal_byminute, cal_bysecond, cal_days, cal_byday
                ) VALUES (
                    :id, :type, :end, :endtime, :frequency, :count,
                    :wkst, :bymonth, :bymonthday, :byyearday, :byweekno,
                    :bysetpos, :byhour, :byminute, :bysecond, :days, :byday
                )";
        
        $this->pdo->prepare($sql)->execute($data);

        // 3. Save Exceptions (EXDATE/RDATE)
        $this->saveExceptions($id, $recurrence);
    }

    private function saveExceptions(int $id, Recurrence $recurrence): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO {$this->tablePrefix}webcal_entry_repeats_not (cal_id, cal_date, cal_exdate) VALUES (:id, :date, :exdate)");
        
        foreach ($recurrence->exDate()->dates() as $date) {
            $stmt->execute(['id' => $id, 'date' => (int)$date->format('Ymd'), 'exdate' => 1]);
        }

        foreach ($recurrence->rDate()->dates() as $date) {
            $stmt->execute(['id' => $id, 'date' => (int)$date->format('Ymd'), 'exdate' => 0]);
        }
    }

    private function mapFreqToType(string $freq): string
    {
        return match ($freq) {
            'DAILY' => 'daily',
            'WEEKLY' => 'weekly',
            'MONTHLY' => 'monthlyByDate', // Simplified, legacy has several monthly types
            'YEARLY' => 'yearly',
            default => 'daily'
        };
    }

    /**
     * @param array<int> $arr
     */
    private function implodeOrNull(array $arr): ?string
    {
        return empty($arr) ? null : implode(',', $arr);
    }

    /**
     * @param array<array{day: string, ordinal: int|null}> $byDay
     */
    private function mapByDayToDaysBitmask(array $byDay, string $freq): ?string
    {
        if ($freq !== 'WEEKLY') {
            return null;
        }

        $days = ['SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6];
        $bitmask = str_repeat('n', 7);
        
        foreach ($byDay as $dayInfo) {
            $day = $dayInfo['day'];
            if (isset($days[$day])) {
                $bitmask[$days[$day]] = 'y';
            }
        }

        return $bitmask;
    }

    /**
     * @param array<array{day: string, ordinal: int|null}> $byDay
     */
    private function mapByDayToLegacyString(array $byDay): ?string
    {
        if (empty($byDay)) {
            return null;
        }

        $parts = [];
        foreach ($byDay as $dayInfo) {
            $dayStr = '';
            if ($dayInfo['ordinal'] !== null) {
                $dayStr .= $dayInfo['ordinal'];
            }
            $dayStr .= $dayInfo['day'];
            $parts[] = $dayStr;
        }

        return implode(',', $parts);
    }
}
