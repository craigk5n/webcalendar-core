<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Repository\CategoryRepositoryInterface;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\ParticipantStatus;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for managing event categories.
 */
final class CategoryService
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        ?LoggerInterface $logger = null,
        // Appended so positional callers keep working. Without it a
        // non-admin cannot assign categories: see assertOnEvent().
        private readonly ?EventRepositoryInterface $eventRepository = null,
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
     * All tags, ordered by name — Epic 23. Tags are global, flat labels
     * stored as a category variant; category listings never include them.
     *
     * @return Category[]
     */
    public function getAllTags(): array
    {
        return $this->categoryRepository->findAllTags();
    }

    /**
     * Returns the tag already stored under this name (case-insensitive),
     * or creates it. Import paths use this so repeated imports never
     * duplicate tags.
     */
    public function matchOrCreateTag(string $name, User $actor): Category
    {
        $existing = $this->categoryRepository->findTagByName($name);
        if ($existing !== null) {
            return $existing;
        }

        $tag = new Category(
            id: $this->categoryRepository->nextId(),
            owner: null,
            name: $name,
            color: null,
            enabled: true,
            isTag: true,
        );
        $this->logger->info('Tag created', ['id' => $tag->id(), 'name' => $tag->name(), 'actor' => $actor->login()]);
        $this->categoryRepository->save($tag);

        return $tag;
    }

    /**
     * Creates a new category.
     *
     * Only the owner (or an admin) may create a personal category, and only
     * an admin may create a global one. Create never overwrites: an existing
     * `(cat_id, cat_owner)` row must be changed through updateCategory().
     *
     * @throws AuthorizationException if actor may not own the new category
     * @throws \DomainException if the category already exists
     */
    public function createCategory(Category $category, User $actor): void
    {
        $this->assertCanModify($category, $actor, 'create category');

        $ownerKey = $category->owner() ?? '';
        $existing = $this->categoryRepository->findByCompositeKey(
            $category->id(),
            $ownerKey
        );
        if ($existing !== null) {
            throw new \DomainException(sprintf(
                'Category with ID %d and owner "%s" already exists.',
                $category->id(),
                $ownerKey,
            ));
        }

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
     * Deletes a category identified by its composite primary key
     * `(cat_id, cat_owner)`. Pass `null` (or `''`) for the owner to
     * target a global category. Required because a single `cat_id`
     * can name both a global row and a user-owned row.
     *
     * @throws \DomainException if the category does not exist.
     * @throws AuthorizationException if actor is not the owner or admin
     */
    public function deleteCategory(int $id, ?string $owner, User $actor): void
    {
        $ownerKey = $owner ?? '';
        $category = $this->categoryRepository->findByCompositeKey($id, $ownerKey);
        if ($category === null) {
            throw new \DomainException(sprintf(
                'Category with ID %d and owner "%s" not found.',
                $id,
                $ownerKey,
            ));
        }

        $this->assertCanModify($category, $actor, 'delete category');
        $this->logger->info('Category deleted', [
            'id' => $id,
            'owner' => $ownerKey,
            'actor' => $actor->login(),
        ]);
        $this->categoryRepository->deleteByCompositeKey($id, $ownerKey);
    }

    /**
     * Assigns multiple categories to an event for a specific user.
     *
     * A non-admin assigns only as themself, only on an event they created
     * or participate in, and only categories that are global or their own.
     *
     * @param int[] $categoryIds
     * @throws AuthorizationException if the actor may not make this assignment
     */
    public function assignToEvent(EventId $eventId, string $userLogin, array $categoryIds, User $actor): void
    {
        if (!$actor->isAdmin()) {
            if ($actor->login() !== $userLogin) {
                throw AuthorizationException::notOwner('assign categories', $eventId->value(), $actor->login());
            }
            $this->assertOnEvent($eventId, $userLogin);
            foreach ($categoryIds as $categoryId) {
                $this->assertCanUseCategory($categoryId, $userLogin);
            }
        }

        $this->logger->debug('Categories assigned to event', ['event_id' => $eventId->value(), 'categories' => $categoryIds]);
        $this->categoryRepository->assignToEvent($eventId, $userLogin, $categoryIds);
    }

    /**
     * Asserts that the login created the event or is a participant on it
     * who has not rejected or deleted it.
     */
    private function assertOnEvent(EventId $eventId, string $login): void
    {
        if ($this->eventRepository === null) {
            // Fail closed: without the event there is nothing to check.
            throw new \LogicException(
                'CategoryService needs an EventRepositoryInterface to let non-admins assign categories.'
            );
        }

        $event = $this->eventRepository->findById($eventId);
        if ($event !== null && $event->createdBy() === $login) {
            return;
        }
        $status = $event !== null
            ? $this->eventRepository->getParticipantsWithStatus($eventId)[$login] ?? null
            : null;
        $declined = [ParticipantStatus::REJECTED->value, ParticipantStatus::DELETED->value];
        if ($status !== null && !in_array($status, $declined, true)) {
            return;
        }

        throw AuthorizationException::notOwner('assign categories', $eventId->value(), $login);
    }

    /**
     * Asserts that the category is global or owned by the login.
     */
    private function assertCanUseCategory(int $categoryId, string $login): void
    {
        if ($this->categoryRepository->findByCompositeKey($categoryId, '') !== null) {
            return;
        }
        if ($this->categoryRepository->findByCompositeKey($categoryId, $login) !== null) {
            return;
        }

        throw AuthorizationException::notOwner('assign category', $categoryId, $login);
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
