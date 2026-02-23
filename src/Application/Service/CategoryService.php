<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Repository\CategoryRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for managing event categories.
 */
final readonly class CategoryService
{
    private LoggerInterface $logger;

    public function __construct(
        private CategoryRepositoryInterface $categoryRepository,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
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

    /**
     * Creates a new category.
     */
    public function createCategory(Category $category, User $actor): void
    {
        $this->logger->info('Category created', ['id' => $category->id(), 'name' => $category->name(), 'actor' => $actor->login()]);
        $this->categoryRepository->save($category);
    }

    /**
     * Updates an existing category.
     * 
     * @throws AuthorizationException if actor is not the owner or admin
     */
    public function updateCategory(Category $category, User $actor): void
    {
        $this->assertCanModify($category, $actor, 'update category');
        $this->logger->info('Category updated', ['id' => $category->id(), 'actor' => $actor->login()]);
        $this->categoryRepository->save($category);
    }

    /**
     * Deletes a category.
     *
     * @throws \DomainException if the category does not exist.
     * @throws AuthorizationException if actor is not the owner or admin
     */
    public function deleteCategory(int $id, User $actor): void
    {
        $category = $this->categoryRepository->findById($id);
        if ($category === null) {
            throw new \DomainException(sprintf('Category with ID %d not found.', $id));
        }

        $this->assertCanModify($category, $actor, 'delete category');
        $this->logger->info('Category deleted', ['id' => $id, 'actor' => $actor->login()]);
        $this->categoryRepository->delete($id);
    }

    /**
     * Assigns multiple categories to an event for a specific user.
     * 
     * @param int[] $categoryIds
     */
    public function assignToEvent(EventId $eventId, string $userLogin, array $categoryIds, User $actor): void
    {
        // User can only assign categories to their own events, or admin can assign to any
        if (!$actor->isAdmin() && $actor->login() !== $userLogin) {
            throw AuthorizationException::notOwner('assign categories', $eventId->value(), $actor->login());
        }
        
        $this->logger->debug('Categories assigned to event', ['event_id' => $eventId->value(), 'categories' => $categoryIds]);
        $this->categoryRepository->assignToEvent($eventId, $userLogin, $categoryIds);
    }

    /**
     * Asserts that the actor can modify the category.
     */
    private function assertCanModify(Category $category, User $actor, string $action): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        $owner = $category->owner();
        if ($owner === null || $owner === '') {
            // Global category - only admin can modify
            throw AuthorizationException::adminRequired($action);
        }

        if ($owner === $actor->login()) {
            return;
        }

        throw AuthorizationException::notOwner($action, $category->id(), $actor->login());
    }
}
