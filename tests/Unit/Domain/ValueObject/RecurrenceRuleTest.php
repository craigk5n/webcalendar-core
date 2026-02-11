<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\ValueObject\RecurrenceRule;

final class RecurrenceRuleTest extends TestCase
{
    public function testCanBeCreatedFromValidRRuleString(): void
    {
        $rruleString = 'FREQ=WEEKLY;BYDAY=MO,WE,FR;UNTIL=20261231T235959Z';
        $rrule = new RecurrenceRule($rruleString);
        
        $this->assertSame($rruleString, $rrule->toString());
    }

    public function testThrowsExceptionForInvalidRRuleString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RecurrenceRule('INVALID_RRULE');
    }

    public function testCanBeCreatedFromParts(): void
    {
        // This might depend on how we want to implement it. 
        // For now, let's stick to the string construction as requested in Task 3.1 acceptance criteria.
        $rrule = RecurrenceRule::fromParts([
            'FREQ' => 'DAILY',
            'COUNT' => 10
        ]);
        
        $this->assertSame('FREQ=DAILY;COUNT=10', $rrule->toString());
    }
}
