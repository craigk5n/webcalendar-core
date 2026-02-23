<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Task;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Exception\EventNotFoundException;
use WebCalendar\Core\Domain\Repository\TaskRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for orchestrating Task-related business logic.
 */
final readonly class TaskService
{
    private LoggerInterface $logger;

    public function __construct(
        private TaskRepositoryInterface $taskRepository,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function getTaskById(EventId $id): ?Task
    {
        return $this->taskRepository->findById($id);
    }

    /**
     * @return Task[]
     */
    public function getTasksInDateRange(DateRange $range, ?string $user = null): array
    {
        return $this->taskRepository->findByDateRange($range, $user);
    }

    /**
     * Creates a new task.
     */
    public function createTask(Task $task, User $actor): void
    {
        $this->logger->info('Task created', ['id' => $task->id()->value(), 'actor' => $actor->login()]);
        $this->taskRepository->save($task);
    }

    /**
     * Updates an existing task.
     * 
     * @throws AuthorizationException if actor is not the owner or admin
     */
    public function updateTask(Task $task, User $actor): void
    {
        $this->assertCanModify($task, $actor, 'update task');
        $this->logger->info('Task updated', ['id' => $task->id()->value(), 'actor' => $actor->login()]);
        $this->taskRepository->save($task);
    }

    /**
     * Deletes a task.
     *
     * @throws EventNotFoundException if the task does not exist.
     * @throws AuthorizationException if actor is not the owner or admin
     */
    public function deleteTask(EventId $id, User $actor): void
    {
        $task = $this->taskRepository->findById($id);
        if ($task === null) {
            throw EventNotFoundException::forId($id);
        }

        $this->assertCanModify($task, $actor, 'delete task');
        $this->logger->info('Task deleted', ['id' => $id->value(), 'actor' => $actor->login()]);
        $this->taskRepository->delete($id);
    }

    /**
     * Asserts that the actor can modify the task.
     */
    private function assertCanModify(Task $task, User $actor, string $action): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if ($task->createdBy() === $actor->login()) {
            return;
        }

        throw AuthorizationException::notOwner($action, $task->id()->value(), $actor->login());
    }
}
