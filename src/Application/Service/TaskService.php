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
use WebCalendar\Core\Domain\ValueObject\EventScope;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for orchestrating Task-related business logic.
 */
final class TaskService
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly TaskRepositoryInterface $taskRepository,
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
    public function getTasksInDateRange(DateRange $range, EventScope $scope): array
    {
        return $this->taskRepository->findByDateRange($range, $scope);
    }

    /**
     * Creates a new task.
     *
     * @throws \InvalidArgumentException if the task already has an id
     * @throws AuthorizationException if a non-admin creates a task for
     *   another user
     */
    public function createTask(Task $task, User $actor): void
    {
        if ($task->id()->value() !== 0) {
            throw new \InvalidArgumentException(
                'createTask requires a new task (id 0); use updateTask'
            );
        }
        if (!$actor->isAdmin() && $task->createdBy() !== $actor->login()) {
            throw AuthorizationException::notSelf(
                'create task',
                $task->createdBy(),
                $actor->login()
            );
        }

        $this->logger->info('Task created', ['id' => $task->id()->value(), 'actor' => $actor->login()]);
        $this->taskRepository->save($task);
    }

    /**
     * Updates an existing task.
     *
     * Authorization is checked against the stored task, not the incoming one.
     *
     * @throws EventNotFoundException if no task exists with that id
     * @throws AuthorizationException if actor is not the owner or admin, or a
     *   non-admin tries to change the owner
     */
    public function updateTask(Task $task, User $actor): void
    {
        $existing = $this->taskRepository->findById($task->id());
        if ($existing === null) {
            throw EventNotFoundException::forId($task->id());
        }

        $this->assertCanModify($existing, $actor, 'update task');
        if (
            !$actor->isAdmin()
            && $task->createdBy() !== $existing->createdBy()
        ) {
            throw AuthorizationException::notOwner(
                'reassign task',
                $task->id()->value(),
                $actor->login()
            );
        }

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
