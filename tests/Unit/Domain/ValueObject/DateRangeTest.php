<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\ValueObject\DateRange;

final class DateRangeTest extends TestCase
{
    public function testCanBeCreatedFromValidDates(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $end = new \DateTimeImmutable('2026-02-11 11:00:00');
        
        $range = new DateRange($start, $end);
        
        $this->assertEquals($start, $range->startDate());
        $this->assertEquals($end, $range->endDate());
    }

    public function testCanBeCreatedWithSameStartAndEnd(): void
    {
        $date = new \DateTimeImmutable('2026-02-11 10:00:00');
        $range = new DateRange($date, $date);
        
        $this->assertEquals($date, $range->startDate());
        $this->assertEquals($date, $range->endDate());
    }

    public function testThrowsExceptionIfStartAfterEnd(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 11:00:00');
        $end = new \DateTimeImmutable('2026-02-11 10:00:00');
        
        $this->expectException(\InvalidArgumentException::class);
        new DateRange($start, $end);
    }

    public function testContainsDate(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $end = new \DateTimeImmutable('2026-02-11 12:00:00');
        $range = new DateRange($start, $end);
        
        $this->assertTrue($range->contains(new \DateTimeImmutable('2026-02-11 10:00:00')));
        $this->assertTrue($range->contains(new \DateTimeImmutable('2026-02-11 11:00:00')));
        $this->assertTrue($range->contains(new \DateTimeImmutable('2026-02-11 12:00:00')));
        $this->assertFalse($range->contains(new \DateTimeImmutable('2026-02-11 09:59:59')));
        $this->assertFalse($range->contains(new \DateTimeImmutable('2026-02-11 12:00:01')));
    }

    public function testOverlaps(): void
    {
        $range1 = new DateRange(
            new \DateTimeImmutable('2026-02-11 10:00:00'),
            new \DateTimeImmutable('2026-02-11 12:00:00')
        );

        // Overlapping ranges
        $this->assertTrue($range1->overlaps(new DateRange(
            new \DateTimeImmutable('2026-02-11 11:00:00'),
            new \DateTimeImmutable('2026-02-11 13:00:00')
        )));
        $this->assertTrue($range1->overlaps(new DateRange(
            new \DateTimeImmutable('2026-02-11 09:00:00'),
            new \DateTimeImmutable('2026-02-11 11:00:00')
        )));
        $this->assertTrue($range1->overlaps(new DateRange(
            new \DateTimeImmutable('2026-02-11 10:30:00'),
            new \DateTimeImmutable('2026-02-11 11:30:00')
        )));
        $this->assertTrue($range1->overlaps(new DateRange(
            new \DateTimeImmutable('2026-02-11 09:00:00'),
            new \DateTimeImmutable('2026-02-11 13:00:00')
        )));

        // Non-overlapping ranges
        $this->assertFalse($range1->overlaps(new DateRange(
            new \DateTimeImmutable('2026-02-11 12:00:01'),
            new \DateTimeImmutable('2026-02-11 13:00:00')
        )));
        $this->assertFalse($range1->overlaps(new DateRange(
            new \DateTimeImmutable('2026-02-11 08:00:00'),
            new \DateTimeImmutable('2026-02-11 09:59:59')
        )));
        
        // Adjacent ranges (should they overlap?) 
        // Typically, ranges are [start, end], so if end1 == start2, they "touch".
        // Let's decide if they overlap. If it's inclusive, yes.
        $this->assertTrue($range1->overlaps(new DateRange(
            new \DateTimeImmutable('2026-02-11 12:00:00'),
            new \DateTimeImmutable('2026-02-11 13:00:00')
        )));
    }
}
