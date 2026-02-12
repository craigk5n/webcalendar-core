<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\Repository\CategoryRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;

/**
 * Service for managing event categories.
 */
final readonly class CategoryService
{
    public function __construct(
        private CategoryRepositoryInterface $categoryRepository
    ) {
    }

    /**
     * Gets all categories accessible to a user (global + user-owned).
     * 
     * @return Category[]
     */
    public function getCategoriesForUser(string $login): array
    {
        $global = $this->categoryRepository->findAllGlobal();
        $personal = $this->categoryRepository->findByOwner($login);
        
        return array_merge($global, $personal);
    }

    public function createCategory(Category $category): void
    {
        $this->categoryRepository->save($category);
    }

    public function updateCategory(Category $category): void
    {
        $this->categoryRepository->save($category);
    }

    public function deleteCategory(int $id): void
    {
        $this->categoryRepository->delete($id);
    }

    /**
     * Assigns multiple categories to an event for a specific user.
     * 
     * @param int[] $categoryIds
     */
    public function assignToEvent(EventId $eventId, string $userLogin, array $categoryIds): void
    {
        $this->categoryRepository->assignToEvent($eventId, $userLogin, $categoryIds);
    }
}
