<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * What one user may do with another user's entries, as a 9-bit mask.
 *
 * This is legacy's `webcal_access_user.cal_can_view` / `cal_can_edit` /
 * `cal_can_approve` column, and the bit layout is load-bearing -- existing
 * installations carry these integers, so the numbering cannot be redesigned.
 *
 * Three entry types times three access levels, one bit each:
 *
 *     bit = accessLevelIndex * 3 + entryTypeIndex
 *
 *            event  task  journal
 *     public     0     1        2
 *     confid.    3     4        5
 *     private    6     7        8
 *
 * Legacy exposes the rows and columns of that grid as constants -- EVENT_WT
 * 73, TASK_WT 146, JOURNAL_WT 292, PUBLIC_WT 7, CONF_WT 56, PRIVATE_WT 448,
 * CAN_DOALL 511 -- and tests a single cell by intersecting one of each.
 */
final class CalendarPermission
{
    /** No access to anything. */
    public const NONE = 0;

    /** Legacy CAN_DOALL: every type at every access level. */
    public const ALL = 511;

    /** Columns of the grid: one entry type across all access levels. */
    private const EVENT_WEIGHT = 73;
    private const TASK_WEIGHT = 146;
    private const JOURNAL_WEIGHT = 292;

    /** Rows of the grid: one access level across all entry types. */
    private const PUBLIC_WEIGHT = 7;
    private const CONFIDENTIAL_WEIGHT = 56;
    private const PRIVATE_WEIGHT = 448;

    /**
     * @throws \InvalidArgumentException If the mask falls outside 0-511.
     */
    public function __construct(private readonly int $mask)
    {
        if ($this->mask < self::NONE || $this->mask > self::ALL) {
            throw new \InvalidArgumentException(
                sprintf('Calendar permission mask must be between %d and %d, got %d.', self::NONE, self::ALL, $this->mask)
            );
        }
    }

    public static function none(): self
    {
        return new self(self::NONE);
    }

    public static function all(): self
    {
        return new self(self::ALL);
    }

    /**
     * Builds a mask from a stored column, treating anything unusable as no
     * access.  Legacy tolerates non-numeric values here (`if (!is_numeric($ret))
     * $ret = 0;`), and a migrated row can carry NULL or junk; denying is the
     * safe reading of a value nobody can interpret.
     */
    public static function fromStorage(mixed $value): self
    {
        if (is_int($value)) {
            return new self(max(self::NONE, min(self::ALL, $value)));
        }

        if (is_string($value) && is_numeric($value)) {
            return new self(max(self::NONE, min(self::ALL, (int)$value)));
        }

        return self::none();
    }

    public function value(): int
    {
        return $this->mask;
    }

    /**
     * Tests the single cell of the grid for this type and access level.
     */
    public function allows(EventType $type, AccessLevel $access): bool
    {
        $cell = self::typeWeight($type) & self::accessWeight($access);

        return ($this->mask & $cell) !== 0;
    }

    public function isNone(): bool
    {
        return $this->mask === self::NONE;
    }

    public function isAll(): bool
    {
        return $this->mask === self::ALL;
    }

    public function equals(self $other): bool
    {
        return $this->mask === $other->mask;
    }

    /**
     * Repeating entries share their base type's bits: legacy maps E and M to
     * EVENT_WT, T and N to TASK_WT, J and O to JOURNAL_WT.
     */
    private static function typeWeight(EventType $type): int
    {
        return match ($type) {
            EventType::EVENT, EventType::REPEATING_EVENT => self::EVENT_WEIGHT,
            EventType::TASK, EventType::REPEATING_TASK => self::TASK_WEIGHT,
            EventType::JOURNAL, EventType::REPEATING_JOURNAL => self::JOURNAL_WEIGHT,
        };
    }

    private static function accessWeight(AccessLevel $access): int
    {
        return match ($access) {
            AccessLevel::PUBLIC => self::PUBLIC_WEIGHT,
            AccessLevel::CONFIDENTIAL => self::CONFIDENTIAL_WEIGHT,
            AccessLevel::PRIVATE => self::PRIVATE_WEIGHT,
        };
    }
}
