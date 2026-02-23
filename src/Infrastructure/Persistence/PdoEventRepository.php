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

    public function search(string $keyword, ?DateRange $range = null, ?User $user = null, ?string $accessLevel = null, ?int $limit = null): \WebCalendar\Core\Domain\ValueObject\EventCollection
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

        if ($accessLevel !== null) {
            $sql .= ' AND cal_access = :access';
            $params['access'] = $accessLevel;
        }

        $sql .= ' ORDER BY cal_date DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        $ids = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $id = is_numeric($row['cal_id'] ?? null) ? (int)$row['cal_id'] : 0;
                $rows[] = $row;
                $ids[] = $id;
            }
        }

        $recurrences = $this->batchLoadRecurrences($ids);
        $events = [];
        foreach ($rows as $row) {
            $id = is_numeric($row['cal_id'] ?? null) ? (int)$row['cal_id'] : 0;
            $events[] = $this->mapRowToEvent($row, $recurrences[$id] ?? null);
        }

        return new \WebCalendar\Core\Domain\ValueObject\EventCollection($events);
    }

    /**
     * @param string[]|null $users
     * @return Event[]
     */
    public function findByDateRange(
        DateRange $range,
        ?User $user = null,
        ?string $accessLevel = null,
        ?array $users = null,
    ): array {
        $startDateInt = (int)$range->startDate()->format('Ymd');
        $endDateInt = (int)$range->endDate()->format('Ymd');

        // Fetch events whose cal_date falls within the window, PLUS recurring
        // events whose cal_date is before the window but whose recurrence
        // extends into or past it.  RecurrenceService::expand() clips
        // occurrences to the actual date range, so over-fetching is safe.
        $sql = "SELECT e.* FROM {$this->tablePrefix}webcal_entry e
                WHERE (
                    e.cal_date BETWEEN :start AND :end
                    OR (
                        e.cal_date < :repeat_cutoff
                        AND EXISTS (
                            SELECT 1 FROM {$this->tablePrefix}webcal_entry_repeats r
                            WHERE r.cal_id = e.cal_id
                            AND (r.cal_end IS NULL OR r.cal_end = 0 OR r.cal_end >= :repeat_min)
                        )
                    )
                )";
        $params = [
            'start' => $startDateInt,
            'end' => $endDateInt,
            'repeat_cutoff' => $startDateInt,
            'repeat_min' => $startDateInt,
        ];

        // Access level filtering
        if ($user !== null) {
            // Logged-in non-admin: see public events + own events
            $sql .= " AND (e.cal_access = 'P' OR e.cal_create_by = :login)";
            $params['login'] = $user->login();
        } elseif ($accessLevel !== null) {
            // Anonymous visitor: only see events matching access level (typically 'P')
            $sql .= ' AND e.cal_access = :access_level';
            $params['access_level'] = $accessLevel;
        }
        // When both $user and $accessLevel are null: admin path, no access filter

        // Optional users filter (restrict to specific creators)
        if ($users !== null && $users !== []) {
            $placeholders = [];
            foreach ($users as $i => $login) {
                $key = 'user_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $login;
            }
            $sql .= ' AND e.cal_create_by IN (' . implode(', ', $placeholders) . ')';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        $ids = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $id = is_numeric($row['cal_id'] ?? null) ? (int)$row['cal_id'] : 0;
                $rows[] = $row;
                $ids[] = $id;
            }
        }

        $recurrences = $this->batchLoadRecurrences($ids);
        $events = [];
        foreach ($rows as $row) {
            $id = is_numeric($row['cal_id'] ?? null) ? (int)$row['cal_id'] : 0;
            $events[] = $this->mapRowToEvent($row, $recurrences[$id] ?? null);
        }

        return $events;
    }

    public function save(Event $event): void
    {
        $idValue = $event->id()->value();
        $isNew = ($idValue === 0);

        $this->executeInTransaction(function () use ($event, &$idValue, $isNew): void {
            if ($isNew) {
                $idValue = $this->getNextId();
            }

            $data = [
                'id' => $idValue,
                'create_by' => $event->createdBy(),
                'date' => (int)$event->start()->format('Ymd'),
                'time' => $event->isAllDay() ? -1 : (int)$event->start()->format('His'),
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

            // Auto-create participant row for the event creator on new events.
            if ($isNew) {
                $this->pdo->prepare(
                    "INSERT INTO {$this->tablePrefix}webcal_entry_user (cal_id, cal_login, cal_status) VALUES (:id, :login, 'A')"
                )->execute(['id' => $idValue, 'login' => $event->createdBy()]);
            }

            $this->saveRecurrence($idValue, $event->recurrence());
        });
    }

    public function create(Event $event): void
    {
        $this->save($event);
    }

    public function delete(EventId $id): void
    {
        $idValue = $id->value();
        
        $this->executeInTransaction(function () use ($idValue): void {
            // Delete from all related tables
            $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_entry_user WHERE cal_id = :id")
                ->execute(['id' => $idValue]);
            $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_entry_repeats WHERE cal_id = :id")
                ->execute(['id' => $idValue]);
            $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_entry_repeats_not WHERE cal_id = :id")
                ->execute(['id' => $idValue]);
            $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_entry WHERE cal_id = :id")
                ->execute(['id' => $idValue]);
        });
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

    public function getParticipants(EventId $id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT cal_login FROM {$this->tablePrefix}webcal_entry_user WHERE cal_id = :id"
        );
        $stmt->execute(['id' => $id->value()]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getParticipantsBatch(array $eventIds): array
    {
        if (empty($eventIds)) {
            return [];
        }

        $ids = array_map(fn(EventId $id) => $id->value(), $eventIds);
        $map = array_fill_keys($ids, []);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT cal_id, cal_login FROM {$this->tablePrefix}webcal_entry_user WHERE cal_id IN ($placeholders)"
        );
        $stmt->execute($ids);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['cal_id']][] = $row['cal_login'];
        }

        return $map;
    }

    public function saveParticipants(EventId $id, array $logins): void
    {
        $this->pdo->prepare(
            "DELETE FROM {$this->tablePrefix}webcal_entry_user WHERE cal_id = :id"
        )->execute(['id' => $id->value()]);

        $insert = $this->pdo->prepare(
            "INSERT INTO {$this->tablePrefix}webcal_entry_user (cal_id, cal_login, cal_status) VALUES (:id, :login, 'A')"
        );
        foreach ($logins as $login) {
            $insert->execute(['id' => $id->value(), 'login' => $login]);
        }
    }

    public function findUidsByCreator(string $login): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT cal_uid FROM {$this->tablePrefix}webcal_entry WHERE cal_create_by = :login AND cal_uid IS NOT NULL"
        );
        $stmt->execute(['login' => $login]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function deleteByCreator(string $login): void
    {
        $this->pdo->prepare(
            "DELETE FROM {$this->tablePrefix}webcal_entry WHERE cal_create_by = :login"
        )->execute(['login' => $login]);
    }

    public function findByAccessLevel(string $accessLevel, int $limit, int $offset): array
    {
        $sql = "SELECT * FROM {$this->tablePrefix}webcal_entry WHERE cal_access = :access ORDER BY cal_id ASC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['access' => $accessLevel]);

        $rows = [];
        $ids = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $id = is_numeric($row['cal_id'] ?? null) ? (int)$row['cal_id'] : 0;
                $rows[] = $row;
                $ids[] = $id;
            }
        }

        $recurrences = $this->batchLoadRecurrences($ids);
        $events = [];
        foreach ($rows as $row) {
            $id = is_numeric($row['cal_id'] ?? null) ? (int)$row['cal_id'] : 0;
            $events[] = $this->mapRowToEvent($row, $recurrences[$id] ?? null);
        }

        return $events;
    }

    public function countByAccessLevel(string $accessLevel): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$this->tablePrefix}webcal_entry WHERE cal_access = :access"
        );
        $stmt->execute(['access' => $accessLevel]);
        return (int)$stmt->fetchColumn();
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
    private function mapRowToEvent(array $row, ?Recurrence $preloadedRecurrence = null): Event
    {
        $rawDate = $row['cal_date'] ?? '';
        $dateStr = is_scalar($rawDate) ? (string)$rawDate : '';

        $rawTime = $row['cal_time'] ?? 0;
        $timeInt = is_numeric($rawTime) ? (int)$rawTime : 0;
        $allDay = ($timeInt === -1);

        if ($allDay) {
            // All-day event: start at midnight, no time component
            $start = \DateTimeImmutable::createFromFormat('Ymd', $dateStr);
            if ($start === false) {
                $start = new \DateTimeImmutable($dateStr !== '' ? $dateStr : 'now');
            }
            $start = $start->setTime(0, 0, 0);
        } else {
            $timeStr = str_pad((string)$timeInt, 6, '0', STR_PAD_LEFT);
            $start = \DateTimeImmutable::createFromFormat('YmdHis', $dateStr . $timeStr);
            if ($start === false) {
                $start = new \DateTimeImmutable($dateStr !== '' ? $dateStr : 'now');
            }
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

        $recurrence = $preloadedRecurrence ?? $this->loadRecurrence($id);

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
            status: $status,
            allDay: $allDay,
            modDate: is_numeric($row['cal_mod_date'] ?? null) ? (int)$row['cal_mod_date'] : null,
            modTime: is_numeric($row['cal_mod_time'] ?? null) ? (int)$row['cal_mod_time'] : null,
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
     * Batch-load recurrence rules and exceptions for multiple event IDs.
     *
     * Replaces per-row loadRecurrence() calls with 2 bulk queries,
     * eliminating the N+1 query problem in findByDateRange()/search().
     *
     * @param int[] $ids
     * @return array<int, Recurrence>
     */
    private function batchLoadRecurrences(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // 1. Batch-load all recurrence rules
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->tablePrefix}webcal_entry_repeats WHERE cal_id IN ($placeholders)"
        );
        $stmt->execute(array_values($ids));

        $rules = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $calId = is_numeric($row['cal_id'] ?? null) ? (int)$row['cal_id'] : 0;
                $rules[$calId] = $this->mapRowToRecurrenceRule($row);
            }
        }

        // 2. Batch-load all exceptions (EXDATE + RDATE)
        $stmt = $this->pdo->prepare(
            "SELECT cal_id, cal_date, cal_exdate FROM {$this->tablePrefix}webcal_entry_repeats_not WHERE cal_id IN ($placeholders)"
        );
        $stmt->execute(array_values($ids));

        /** @var array<int, \DateTimeImmutable[]> $exDates */
        $exDates = [];
        /** @var array<int, \DateTimeImmutable[]> $rDates */
        $rDates = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $calId = is_numeric($row['cal_id'] ?? null) ? (int)$row['cal_id'] : 0;
                $dateStr = (string)$row['cal_date'];
                $date = \DateTimeImmutable::createFromFormat('Ymd', $dateStr);
                if ($date !== false) {
                    if ((int)$row['cal_exdate'] === 1) {
                        $exDates[$calId][] = $date;
                    } else {
                        $rDates[$calId][] = $date;
                    }
                }
            }
        }

        // 3. Build Recurrence objects indexed by event ID
        $result = [];
        foreach ($ids as $id) {
            $result[$id] = new Recurrence(
                $rules[$id] ?? null,
                new ExDate($exDates[$id] ?? []),
                new RDate($rDates[$id] ?? [])
            );
        }

        return $result;
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

    /**
     * Executes a callback within a database transaction.
     * 
     * @param callable $callback The operation to execute
     * @throws \Throwable Re-throws any exception after rollback
     */
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
