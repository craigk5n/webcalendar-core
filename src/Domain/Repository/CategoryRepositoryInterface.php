<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\ValueObject\EventId;

/**
 * Interface for Category persistence operations.
 */
interface CategoryRepositoryInterface
{
    public function findById(int $id): ?Category;

    public function findByName(string $name, ?string $owner = null): ?Category;

    public function nextId(): int;

    /**
     * @return Category[]
     */
    public function findByOwner(?string $owner): array;

    /**
     * @return Category[]
     */
    public function findAllGlobal(): array;

    public function save(Category $category): void;

    /**
     * Creates a new category. Alias for save() used by ImportService.
     */
    public function create(Category $category): void;

    public function delete(int $id): void;

    /**
     * Assigns categories to an event for a specific user.
     * 
     * @param int[] $categoryIds
     */
    public function assignToEvent(EventId $eventId, string $userLogin, array $categoryIds): void;

    /**
     * Gets categories assigned to an event for a user.
     * @return Category[]
     */
    public function getForEvent(EventId $eventId, string $userLogin): array;

    /**
     * Gets the number of events assigned to a category.
     */
    public function getEventCount(int $catId): int;

    /**
     * Reassigns all events from one category to another, deduplicating.
     */
    public function reassignEvents(int $fromCatId, int $toCatId, string $userLogin): void;
}
