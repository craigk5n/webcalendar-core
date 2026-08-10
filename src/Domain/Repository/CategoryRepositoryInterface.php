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
    /**
     * @deprecated Ambiguous when a `cat_id` is shared across owners.
     *     Prefer {@see findByCompositeKey()}.
     */
    public function findById(int $id): ?Category;

    /**
     * Fetches a single category by its composite primary key
     * `(cat_id, cat_owner)`. Use `''` as the owner for global categories.
     */
    public function findByCompositeKey(int $id, string $owner): ?Category;

    /**
     * Category lookup by name (case-insensitive). Tags are excluded —
     * use {@see findTagByName()} for those.
     */
    public function findByName(string $name, ?string $owner = null): ?Category;

    /**
     * Tag lookup by name (case-insensitive) — the imports'
     * match-or-create seam for tags.
     */
    public function findTagByName(string $name): ?Category;

    /**
     * All tags, ordered by name. Category listings (findByOwner,
     * findAllGlobal) never include tags.
     *
     * @return Category[]
     */
    public function findAllTags(): array;

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

    /**
     * @deprecated Deletes every category row sharing this `cat_id`.
     *     Prefer {@see deleteByCompositeKey()}.
     */
    public function delete(int $id): void;

    /**
     * Deletes a single category by its composite primary key
     * `(cat_id, cat_owner)`. Use `''` for global categories.
     */
    public function deleteByCompositeKey(int $id, string $owner): void;

    /**
     * Assigns categories to an event for a specific user.
     *
     * Replaces the whole assignment set for `(event, user)` — categories
     * and tags share this junction table, so a caller that manages both
     * must pass both in one call or the omitted kind is deleted.
     *
     * @param int[] $categoryIds
     */
    public function assignToEvent(EventId $eventId, string $userLogin, array $categoryIds): void;

    /**
     * Gets everything assigned to an event for a user — categories *and*
     * tags, in assignment order.
     *
     * Deliberately unfiltered: it returns whole Category objects, so
     * callers select with {@see Category::isTag()}, and copy operations
     * (EventDuplicationService) need the complete set. Use
     * {@see getTagsForEvent()} when only tags are wanted, and
     * {@see getForEventsBatch()} for the primary category alone.
     *
     * @return Category[]
     */
    public function getForEvent(EventId $eventId, string $userLogin): array;

    /**
     * Gets tags assigned to an event for a user, in assignment order.
     *
     * @return Category[] Every element has `isTag() === true`.
     */
    public function getTagsForEvent(EventId $eventId, string $userLogin): array;

    /**
     * Gets the primary category for multiple events in a batch query.
     *
     * Tags are excluded: they share the junction table with categories, so
     * without the filter a tag ordered ahead of the category was returned
     * as the event's category, carrying a null colour with it.
     *
     * @param EventId[] $eventIds
     * @return array<int, array{id: int, color: string|null}> Map of event_id => category info.
     */
    public function getForEventsBatch(array $eventIds, string $userLogin): array;

    /**
     * Gets tags for multiple events in a batch query.
     *
     * Events with no tags are absent from the map rather than present with
     * an empty list; callers use `?? []`.
     *
     * @param EventId[] $eventIds
     * @return array<int, list<array{id: int, name: string}>> Map of event_id => tags.
     */
    public function getTagsForEventsBatch(array $eventIds, string $userLogin): array;

    /**
     * Gets the number of events assigned to a category.
     *
     * @deprecated Inflates counts when a `cat_id` is shared by a global
     *     and a user-owned category. Prefer {@see getEventCountByOwner()}.
     */
    public function getEventCount(int $catId): int;

    /**
     * Counts distinct events assigned to a specific category matched by
     * its composite primary key `(cat_id, cat_owner)`. Resolves the
     * global-vs-personal ambiguity that `getEventCount()` cannot.
     */
    public function getEventCountByOwner(int $catId, string $catOwner): int;

    /**
     * Reassigns the caller's events from one category to another,
     * deduplicating collisions. Matches `$userLogin` against the
     * junction table's `cat_owner`, so other users' rows at the same
     * `cat_id` are untouched. Self-merge (`$fromCatId === $toCatId`)
     * is a guarded no-op.
     */
    public function reassignEvents(int $fromCatId, int $toCatId, string $userLogin): void;
}
