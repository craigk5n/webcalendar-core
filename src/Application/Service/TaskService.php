<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Task;
use WebCalendar\Core\Domain\Repository\TaskRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\DateRange;

/**
 * Service for orchestrating Task-related business logic.
 */
final readonly class TaskService
{
    public function __construct(
        private TaskRepositoryInterface $taskRepository
    ) {
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

    public function createTask(Task $task): void
    {
        $this->taskRepository->save($task);
    }

    public function updateTask(Task $task): void
    {
        $this->taskRepository->save($task);
    }

    public function deleteTask(EventId $id): void
    {
        $this->taskRepository->delete($id);
    }
}
