<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;

final class EnumsTest extends TestCase
{
    public function testEventTypeEnum(): void
    {
        $this->assertSame('E', EventType::EVENT->value);
        $this->assertSame('M', EventType::REPEATING_EVENT->value);
        $this->assertSame('T', EventType::TASK->value);
        $this->assertSame('J', EventType::JOURNAL->value);
        $this->assertSame('N', EventType::REPEATING_TASK->value);
        $this->assertSame('O', EventType::REPEATING_JOURNAL->value);
        
        $this->assertFalse(EventType::EVENT->isRepeating());
        $this->assertTrue(EventType::REPEATING_EVENT->isRepeating());
        $this->assertFalse(EventType::TASK->isRepeating());
        $this->assertFalse(EventType::JOURNAL->isRepeating());
        $this->assertTrue(EventType::REPEATING_TASK->isRepeating());
        $this->assertTrue(EventType::REPEATING_JOURNAL->isRepeating());
    }

    public function testAccessLevelEnum(): void
    {
        $this->assertSame('P', AccessLevel::PUBLIC->value);
        $this->assertSame('C', AccessLevel::CONFIDENTIAL->value);
        $this->assertSame('R', AccessLevel::PRIVATE->value);
    }
}
