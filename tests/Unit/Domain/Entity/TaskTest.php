<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Task;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;

final class TaskTest extends TestCase
{
    public function testCanBeCreatedWithValidData(): void
    {
        $id = new EventId(1);
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $dueDate = new \DateTimeImmutable('2026-02-12 17:00:00');
        
        $task = new Task(
            id: $id,
            uid: 'task-uid-123',
            name: 'Test Task',
            description: 'This is a test task.',
            location: '',
            start: $start,
            duration: 0,
            createdBy: 'admin',
            type: EventType::TASK,
            access: AccessLevel::PUBLIC,
            dueDate: $dueDate,
            percentComplete: 50
        );

        $this->assertEquals($id, $task->id());
        $this->assertSame(EventType::TASK, $task->type());
        $this->assertEquals($dueDate, $task->dueDate());
        $this->assertSame(50, $task->percentComplete());
    }

    public function testThrowsExceptionForInvalidPercent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Task(
            id: new EventId(1),
            uid: 'uid',
            name: 'Task',
            description: '',
            location: '',
            start: new \DateTimeImmutable(),
            duration: 0,
            createdBy: 'admin',
            type: EventType::TASK,
            access: AccessLevel::PUBLIC,
            dueDate: null,
            percentComplete: 101
        );
    }
}
