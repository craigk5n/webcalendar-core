<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\ActivityLogEntry;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;

final class ActivityLogEntryTest extends TestCase
{
    public function testCanBeCreatedWithValidData(): void
    {
        $date = new \DateTimeImmutable('2026-02-11 10:00:00');
        $log = new ActivityLogEntry(
            id: 1,
            entryId: 123,
            login: 'admin',
            userCal: 'jdoe',
            type: ActivityLogType::CREATE,
            date: $date,
            text: 'Event created'
        );

        $this->assertSame(1, $log->id());
        $this->assertSame(123, $log->entryId());
        $this->assertSame('admin', $log->login());
        $this->assertSame('jdoe', $log->userCal());
        $this->assertSame(ActivityLogType::CREATE, $log->type());
        $this->assertEquals($date, $log->date());
        $this->assertSame('Event created', $log->text());
    }
}
