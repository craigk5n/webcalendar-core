<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Journal;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;

final class JournalTest extends TestCase
{
    public function testCanBeCreatedWithValidData(): void
    {
        $id = new EventId(1);
        $start = new \DateTimeImmutable('2026-02-11 00:00:00');
        
        $journal = new Journal(
            id: $id,
            uid: 'journal-uid-123',
            name: 'Test Journal',
            description: 'This is a test journal entry.',
            location: '',
            start: $start,
            duration: 0,
            createdBy: 'admin',
            type: EventType::JOURNAL,
            access: AccessLevel::PRIVATE
        );

        $this->assertEquals($id, $journal->id());
        $this->assertSame(EventType::JOURNAL, $journal->type());
        $this->assertSame(AccessLevel::PRIVATE, $journal->access());
    }
}
