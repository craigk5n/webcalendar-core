<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\CalendarPermission;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class CalendarPermissionTest extends TestCase
{
    /**
     * The bit layout is inherited from installed data, so it is pinned
     * against legacy's own constants rather than re-derived.
     */
    public function testLegacyWeightsDecomposeAsDocumented(): void
    {
        // EVENT_WT 73, TASK_WT 146, JOURNAL_WT 292 -- one type, all levels.
        $this->assertSame(0b001001001, 73);
        $this->assertSame(0b010010010, 146);
        $this->assertSame(0b100100100, 292);

        // PUBLIC_WT 7, CONF_WT 56, PRIVATE_WT 448 -- one level, all types.
        $this->assertSame(0b000000111, 7);
        $this->assertSame(0b000111000, 56);
        $this->assertSame(0b111000000, 448);

        // CAN_DOALL 511 -- the whole grid.
        $this->assertSame(0b111111111, 511);
        $this->assertSame(511, CalendarPermission::ALL);
    }

    public function testAllAllowsEveryTypeAtEveryLevel(): void
    {
        $all = CalendarPermission::all();

        foreach (EventType::cases() as $type) {
            foreach (AccessLevel::cases() as $access) {
                $this->assertTrue(
                    $all->allows($type, $access),
                    "CAN_DOALL should allow {$type->value}/{$access->value}"
                );
            }
        }
    }

    public function testNoneAllowsNothing(): void
    {
        $none = CalendarPermission::none();

        foreach (EventType::cases() as $type) {
            foreach (AccessLevel::cases() as $access) {
                $this->assertFalse($none->allows($type, $access));
            }
        }
        $this->assertTrue($none->isNone());
    }

    /**
     * A single legacy weight grants one row or column of the grid, nothing
     * else -- this is what makes the mask worth having over a boolean.
     */
    public function testEventWeightGrantsEveryLevelOfEventsAndNoOtherType(): void
    {
        $eventsOnly = new CalendarPermission(73);

        foreach (AccessLevel::cases() as $access) {
            $this->assertTrue($eventsOnly->allows(EventType::EVENT, $access));
            $this->assertFalse($eventsOnly->allows(EventType::TASK, $access));
            $this->assertFalse($eventsOnly->allows(EventType::JOURNAL, $access));
        }
    }

    public function testPublicWeightGrantsEveryTypeAtPublicOnly(): void
    {
        $publicOnly = new CalendarPermission(7);

        foreach ([EventType::EVENT, EventType::TASK, EventType::JOURNAL] as $type) {
            $this->assertTrue($publicOnly->allows($type, AccessLevel::PUBLIC));
            $this->assertFalse($publicOnly->allows($type, AccessLevel::CONFIDENTIAL));
            $this->assertFalse($publicOnly->allows($type, AccessLevel::PRIVATE));
        }
    }

    /**
     * The intersection of one type and one level is a single cell: public
     * events, and nothing else at all.
     */
    public function testASingleCellGrantsExactlyOneCombination(): void
    {
        $publicEventsOnly = new CalendarPermission(73 & 7);

        $this->assertSame(1, $publicEventsOnly->value());
        $this->assertTrue($publicEventsOnly->allows(EventType::EVENT, AccessLevel::PUBLIC));
        $this->assertFalse($publicEventsOnly->allows(EventType::EVENT, AccessLevel::PRIVATE));
        $this->assertFalse($publicEventsOnly->allows(EventType::TASK, AccessLevel::PUBLIC));
    }

    /**
     * Repeating variants share the base type's bits; legacy maps E/M, T/N and
     * J/O onto the same three weights.
     */
    public function testRepeatingTypesShareTheirBaseTypesBits(): void
    {
        $eventsOnly = new CalendarPermission(73);
        $tasksOnly = new CalendarPermission(146);
        $journalsOnly = new CalendarPermission(292);

        $this->assertTrue($eventsOnly->allows(EventType::REPEATING_EVENT, AccessLevel::PUBLIC));
        $this->assertTrue($tasksOnly->allows(EventType::REPEATING_TASK, AccessLevel::PUBLIC));
        $this->assertTrue($journalsOnly->allows(EventType::REPEATING_JOURNAL, AccessLevel::PUBLIC));

        $this->assertFalse($eventsOnly->allows(EventType::REPEATING_TASK, AccessLevel::PUBLIC));
    }

    public function testRejectsMasksOutsideTheGrid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CalendarPermission(512);
    }

    public function testRejectsNegativeMasks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CalendarPermission(-1);
    }

    /**
     * Stored values come from a column that has held anything over the years;
     * legacy coerces non-numerics to 0 and so does this.
     *
     * @dataProvider storedValues
     */
    public function testFromStorageCoercesSafely(mixed $stored, int $expected): void
    {
        $this->assertSame($expected, CalendarPermission::fromStorage($stored)->value());
    }

    /** @return array<string, array{mixed, int}> */
    public static function storedValues(): array
    {
        return [
            'int'            => [255, 255],
            'numeric string' => ['73', 73],
            'zero'           => [0, 0],
            'null'           => [null, 0],
            'empty string'   => ['', 0],
            'junk'           => ['banana', 0],
            'bool'           => [true, 0],
            'over the grid'  => [99999, 511],
            'negative'       => [-5, 0],
        ];
    }

    public function testEquality(): void
    {
        $this->assertTrue((new CalendarPermission(73))->equals(new CalendarPermission(73)));
        $this->assertFalse((new CalendarPermission(73))->equals(new CalendarPermission(146)));
        $this->assertTrue(CalendarPermission::all()->isAll());
    }
}
