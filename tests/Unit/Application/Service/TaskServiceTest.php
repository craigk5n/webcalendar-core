<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\TaskService;
use WebCalendar\Core\Domain\Repository\TaskRepositoryInterface;
use WebCalendar\Core\Domain\Entity\Task;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Exception\EventNotFoundException;
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


    public function testCreateTaskSavesNewEntryOwnedByActor(): void
    {
        $task = $this->createTask(new EventId(0), 'bob');

        $this->taskRepository->expects($this->once())->method('save')->with($task);

        $this->taskService->createTask($task, $this->user('bob'));
    }

    public function testCreateTaskRejectsExistingId(): void
    {
        $this->taskRepository->expects($this->never())->method('save');

        $this->expectException(\InvalidArgumentException::class);
        $this->taskService->createTask($this->createTask(new EventId(7), 'bob'), $this->user('bob'));
    }

    public function testNonAdminCannotCreateTaskOwnedByAnotherUser(): void
    {
        $this->taskRepository->expects($this->never())->method('save');

        $this->expectException(AuthorizationException::class);
        $this->taskService->createTask($this->createTask(new EventId(0), 'alice'), $this->user('bob'));
    }

    public function testAdminCanCreateTaskOwnedByAnotherUser(): void
    {
        $task = $this->createTask(new EventId(0), 'alice');

        $this->taskRepository->expects($this->once())->method('save')->with($task);

        $this->taskService->createTask($task, $this->user('admin', true));
    }

    public function testOwnerCanUpdateOwnTask(): void
    {
        $id = new EventId(5);
        $updated = $this->createTask($id, 'bob', 'Renamed');

        $this->taskRepository->method('findById')->with($id)->willReturn($this->createTask($id, 'bob'));
        $this->taskRepository->expects($this->once())->method('save')->with($updated);

        $this->taskService->updateTask($updated, $this->user('bob'));
    }

    public function testUpdateTaskChecksStoredOwnerNotIncomingEntity(): void
    {
        $id = new EventId(5);
        // Caller claims ownership of alice's entry by setting createdBy to itself.
        $forged = $this->createTask($id, 'bob', 'Overwritten');

        $this->taskRepository->method('findById')->with($id)->willReturn($this->createTask($id, 'alice'));
        $this->taskRepository->expects($this->never())->method('save');

        $this->expectException(AuthorizationException::class);
        $this->taskService->updateTask($forged, $this->user('bob'));
    }

    public function testUpdateTaskThrowsWhenStoredEntryMissing(): void
    {
        // A non-task id (or unknown id) is not returned by the typed findById.
        $id = new EventId(9);

        $this->taskRepository->method('findById')->with($id)->willReturn(null);
        $this->taskRepository->expects($this->never())->method('save');

        $this->expectException(EventNotFoundException::class);
        $this->taskService->updateTask($this->createTask($id, 'bob'), $this->user('bob'));
    }

    public function testOwnerCannotReassignTaskToAnotherUser(): void
    {
        $id = new EventId(5);

        $this->taskRepository->method('findById')->with($id)->willReturn($this->createTask($id, 'bob'));
        $this->taskRepository->expects($this->never())->method('save');

        $this->expectException(AuthorizationException::class);
        $this->taskService->updateTask($this->createTask($id, 'alice'), $this->user('bob'));
    }

    public function testAdminCanUpdateAnyoneTask(): void
    {
        $id = new EventId(5);
        $updated = $this->createTask($id, 'alice', 'Fixed by admin');

        $this->taskRepository->method('findById')->with($id)->willReturn($this->createTask($id, 'alice'));
        $this->taskRepository->expects($this->once())->method('save')->with($updated);

        $this->taskService->updateTask($updated, $this->user('admin', true));
    }

    private function user(string $login, bool $isAdmin = false): User
    {
        return new User($login, 'First', 'Last', $login . '@example.com', $isAdmin, true);
    }

    private function createTask(
        EventId $id,
        string $createdBy = 'admin',
        string $name = 'Task 1'
    ): Task {
        return new Task(
            id: $id,
            uid: 'uid',
            name: $name,
            description: '',
            location: '',
            start: new \DateTimeImmutable(),
            duration: 0,
            createdBy: $createdBy,
            type: EventType::TASK,
            access: AccessLevel::PUBLIC
        );
    }
}
