<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\TaskService;
use WebCalendar\Core\Domain\Repository\TaskRepositoryInterface;
use WebCalendar\Core\Domain\Entity\Task;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;

final class TaskServiceTest extends TestCase
{
    /** @var TaskRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $taskRepository;
    private TaskService $taskService;

    protected function setUp(): void
    {
        $this->taskRepository = $this->createMock(TaskRepositoryInterface::class);
        $this->taskService = new TaskService($this->taskRepository);
    }

    public function testGetTaskById(): void
    {
        $id = new EventId(1);
        $task = $this->createTask($id);
        
        $this->taskRepository->expects($this->once())
            ->method('findById')
            ->with($id)
            ->willReturn($task);

        $this->assertSame($task, $this->taskService->getTaskById($id));
    }

    private function createTask(EventId $id): Task
    {
        return new Task(
            id: $id,
            uid: 'uid',
            name: 'Task 1',
            description: '',
            location: '',
            start: new \DateTimeImmutable(),
            duration: 0,
            createdBy: 'admin',
            type: EventType::TASK,
            access: AccessLevel::PUBLIC
        );
    }
}
