<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Infrastructure\Persistence\PdoCategoryRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

final class PdoCategoryRepositoryTest extends RepositoryTestCase
{
    private PdoCategoryRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PdoCategoryRepository($this->pdo);

        // Clean category-related tables
        $this->pdo->exec('DELETE FROM webcal_entry_categories');
        $this->pdo->exec('DELETE FROM webcal_categories');
    }

    public function testSaveAndFindByCompositeKey(): void
    {
        $category = new Category(1, null, 'Meeting', '#0073aa');
        $this->repository->save($category);

        $found = $this->repository->findByCompositeKey(1, '');
        $this->assertNotNull($found);
        $this->assertSame('Meeting', $found->name());
        $this->assertSame('#0073aa', $found->color());
    }

    public function testFindByNameIsCaseInsensitive(): void
    {
        $category = new Category(1, null, 'Holiday', '#dc3232');
        $this->repository->save($category);

        $found = $this->repository->findByName('holiday');
        $this->assertNotNull($found);
        $this->assertSame('Holiday', $found->name());

        $found = $this->repository->findByName('HOLIDAY');
        $this->assertNotNull($found);
        $this->assertSame('Holiday', $found->name());

        $found = $this->repository->findByName('hoLiDay');
        $this->assertNotNull($found);
        $this->assertSame('Holiday', $found->name());
    }

    public function testFindByNameReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repository->findByName('nonexistent'));
    }

    public function testGetEventCountByOwnerReturnsZeroForNoEvents(): void
    {
        $category = new Category(1, null, 'Empty', '#000');
        $this->repository->save($category);

        $this->assertSame(0, $this->repository->getEventCountByOwner(1, ''));
    }

    public function testGetEventCountByOwnerReturnsCorrectCount(): void
    {
        $category = new Category(1, null, 'Work', '#000');
        $this->repository->save($category);

        // Insert test entries
        $this->insertEntry(100);
        $this->insertEntry(101);
        $this->insertEntry(102);

        // Assign events to category
        $this->repository->assignToEvent(new EventId(100), 'admin', [1]);
        $this->repository->assignToEvent(new EventId(101), 'admin', [1]);
        $this->repository->assignToEvent(new EventId(102), 'admin', [1]);

        $this->assertSame(3, $this->repository->getEventCountByOwner(1, 'admin'));
    }

    public function testGetEventCountByOwnerScopesCountsPerUser(): void
    {
        // Two users each assign the global cat_id=1 to the same event.
        // Each user's assignment is a distinct junction row with its own
        // cat_owner, and the owner-aware count must return each owner's
        // contribution — not a deduped distinct-event total.
        $category = new Category(1, null, 'Shared', '#000');
        $this->repository->save($category);

        $this->insertEntry(100);

        $this->repository->assignToEvent(new EventId(100), 'admin', [1]);
        $this->repository->assignToEvent(new EventId(100), 'jdoe', [1]);

        $this->assertSame(1, $this->repository->getEventCountByOwner(1, 'admin'));
        $this->assertSame(1, $this->repository->getEventCountByOwner(1, 'jdoe'));
    }

    public function testReassignEventsMovesEventsToTarget(): void
    {
        $source = new Category(1, null, 'Source', '#000');
        $target = new Category(2, null, 'Target', '#fff');
        $this->repository->save($source);
        $this->repository->save($target);

        $this->insertEntry(100);
        $this->insertEntry(101);

        $this->repository->assignToEvent(new EventId(100), 'admin', [1]);
        $this->repository->assignToEvent(new EventId(101), 'admin', [1]);

        $this->repository->reassignEvents(1, 2, 'admin');

        $this->assertSame(0, $this->repository->getEventCountByOwner(1, 'admin'));
        $this->assertSame(2, $this->repository->getEventCountByOwner(2, 'admin'));
    }

    public function testReassignEventsDeduplicates(): void
    {
        $source = new Category(1, null, 'Source', '#000');
        $target = new Category(2, null, 'Target', '#fff');
        $this->repository->save($source);
        $this->repository->save($target);

        $this->insertEntry(100);

        // Admin assigns the event to both categories. The reassign from
        // cat 1 to cat 2 for admin must collapse admin's rows into a
        // single row on cat 2 (dedup) without touching jdoe (who is
        // not involved here).
        $this->repository->assignToEvent(new EventId(100), 'admin', [1, 2]);

        $this->repository->reassignEvents(1, 2, 'admin');

        // Source should be empty for admin.
        $this->assertSame(0, $this->repository->getEventCountByOwner(1, 'admin'));
        // Target should still have event 100 exactly once for admin.
        $this->assertSame(1, $this->repository->getEventCountByOwner(2, 'admin'));
    }

    public function testFindAllGlobal(): void
    {
        $this->repository->save(new Category(1, null, 'Global1', '#000'));
        $this->repository->save(new Category(2, null, 'Global2', '#fff'));
        $this->repository->save(new Category(3, 'jdoe', 'Personal', '#ccc'));

        $globals = $this->repository->findAllGlobal();
        $this->assertCount(2, $globals);
    }

    public function testFindByOwner(): void
    {
        $this->repository->save(new Category(1, null, 'Global', '#000'));
        $this->repository->save(new Category(2, 'jdoe', 'JDoe Cat', '#fff'));
        $this->repository->save(new Category(3, 'jdoe', 'JDoe Cat 2', '#ccc'));

        $owned = $this->repository->findByOwner('jdoe');
        $this->assertCount(2, $owned);
    }

    public function testUpdateExistingCategory(): void
    {
        $category = new Category(1, null, 'Original', '#000');
        $this->repository->save($category);

        $updated = new Category(1, null, 'Renamed', '#fff');
        $this->repository->save($updated);

        $found = $this->repository->findByCompositeKey(1, '');
        $this->assertNotNull($found);
        $this->assertSame('Renamed', $found->name());
        $this->assertSame('#fff', $found->color());
    }

    // -- Global vs personal category resolution -----------------------------
    //
    // Regression coverage for a read-path bug: assignToEvent() writes the
    // junction row with `cat_owner = $userLogin` regardless of whether the
    // target category is global (`cat_owner=''`) or personal. Earlier the
    // batch read joined on `ec.cat_owner = c.cat_owner` and so never matched
    // global categories assigned to user events.
    //
    // Both getForEvent() and getForEventsBatch() must return assigned
    // categories whether they are global or personal, and when both versions
    // exist the personal version must win.

    public function testGetForEventReturnsGlobalCategoryAssignedToUserEvent(): void
    {
        // Global category (owner is null / empty-string in DB)
        $this->repository->save(new Category(1, null, 'Holidays', '#ff0000'));
        $this->insertEntry(100);
        $this->repository->assignToEvent(new EventId(100), 'admin', [1]);

        $categories = $this->repository->getForEvent(new EventId(100), 'admin');
        $this->assertCount(1, $categories);
        $this->assertSame('Holidays', $categories[0]->name());
        $this->assertNull($categories[0]->owner(), 'global category should round-trip with null owner');
    }

    public function testGetForEventsBatchReturnsGlobalCategoryAssignedToUserEvent(): void
    {
        $this->repository->save(new Category(1, null, 'Holidays', '#ff0000'));
        $this->insertEntry(100);
        $this->insertEntry(101);
        $this->repository->assignToEvent(new EventId(100), 'admin', [1]);

        $map = $this->repository->getForEventsBatch(
            [new EventId(100), new EventId(101)],
            'admin',
        );
        $this->assertArrayHasKey(100, $map);
        $this->assertSame(1, $map[100]['id']);
        $this->assertSame('#ff0000', $map[100]['color']);
        $this->assertArrayNotHasKey(101, $map, 'unassigned events should not appear');
    }

    public function testGetForEventPrefersPersonalOverGlobalWhenSameCatId(): void
    {
        // Both a global and a personal category share cat_id=1 (legal per
        // the composite primary key `(cat_id, cat_owner)`). The personal
        // version should win for the user who owns it.
        $this->repository->save(new Category(1, null, 'Global Work', '#000000'));
        $this->repository->save(new Category(1, 'admin', 'Personal Work', '#ffffff'));
        $this->insertEntry(100);
        $this->repository->assignToEvent(new EventId(100), 'admin', [1]);

        $categories = $this->repository->getForEvent(new EventId(100), 'admin');
        $this->assertCount(1, $categories);
        $this->assertSame('Personal Work', $categories[0]->name(), 'personal version must win over global');
        $this->assertSame('admin', $categories[0]->owner());
    }

    public function testGetForEventsBatchPrefersPersonalOverGlobalWhenSameCatId(): void
    {
        $this->repository->save(new Category(1, null, 'Global Work', '#000000'));
        $this->repository->save(new Category(1, 'admin', 'Personal Work', '#ffffff'));
        $this->insertEntry(100);
        $this->repository->assignToEvent(new EventId(100), 'admin', [1]);

        $map = $this->repository->getForEventsBatch([new EventId(100)], 'admin');
        $this->assertSame('#ffffff', $map[100]['color'], 'personal color (white) must win over global (black)');
    }

    public function testGetForEventsBatchDoesNotLeakOtherUsersAssignments(): void
    {
        // admin and jdoe each assign the global category to the SAME event
        // id — each should only see their own assignment row.
        $this->repository->save(new Category(1, null, 'Shared', '#cccccc'));
        $this->insertEntry(100);
        $this->repository->assignToEvent(new EventId(100), 'admin', [1]);
        $this->repository->assignToEvent(new EventId(100), 'jdoe', [1]);

        $adminMap = $this->repository->getForEventsBatch([new EventId(100)], 'admin');
        $jdoeMap = $this->repository->getForEventsBatch([new EventId(100)], 'jdoe');

        $this->assertSame(1, $adminMap[100]['id']);
        $this->assertSame(1, $jdoeMap[100]['id']);
    }

    // -- Composite-key regression coverage ---------------------------------
    //
    // `webcal_categories` has PRIMARY KEY (cat_id, cat_owner), so a single
    // numeric cat_id can legitimately name two different categories — one
    // global (cat_owner='') and one user-owned. Earlier versions of this
    // repository ignored cat_owner in reassignEvents / getEventCount /
    // findById / delete, which caused (a) data loss on same-id merges,
    // (b) inflated counts, and (c) non-deterministic lookups. These tests
    // pin the fixed behavior in place.

    public function testReassignEventsDoesNotReuseNamedPlaceholders(): void
    {
        // Regression: the dedupe DELETE in reassignEvents() used to
        // reference `:user` twice while binding it once, which worked
        // under PDO emulated prepares and SQLite but broke with
        // SQLSTATE[HY093] on MySQL/PostgreSQL native prepares (the
        // hardened config that downstream consumers like webcalendar-wp
        // install via `PDO::ATTR_EMULATE_PREPARES=false`).
        //
        // Core's integration suite runs against SQLite, which masks
        // this class of bug entirely. `StrictPlaceholderPdo` closes
        // the gap by rejecting reused named placeholders at prepare()
        // time — any repository method that triggers the pattern will
        // throw here even though the underlying query would succeed
        // on SQLite.
        $strictPdo = new StrictPlaceholderPdo('sqlite::memory:');
        $strictPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->loadSchemaInto($strictPdo);

        $repo = new PdoCategoryRepository($strictPdo);
        $repo->save(new Category(1, null, 'Source', '#000'));
        $repo->save(new Category(2, null, 'Target', '#fff'));

        $strictPdo->exec(
            "INSERT INTO webcal_entry (cal_id, cal_name, cal_date, cal_time, cal_duration, cal_create_by, cal_type, cal_access)
             VALUES (100, 'Test Event', 20260214, 100000, 60, 'admin', 'E', 'P')"
        );

        // Admin has the event in both categories — exercises the
        // dedupe DELETE branch that contained the placeholder-reuse bug.
        $repo->assignToEvent(new EventId(100), 'admin', [1, 2]);

        // Must not throw the strict-placeholder error.
        $repo->reassignEvents(1, 2, 'admin');

        $this->assertSame(0, $repo->getEventCountByOwner(1, 'admin'));
        $this->assertSame(1, $repo->getEventCountByOwner(2, 'admin'));
    }

    /**
     * Loads the SQLite schema into an arbitrary PDO connection so a
     * test can use a PDO subclass (e.g. StrictPlaceholderPdo) without
     * going through RepositoryTestCase::setUp().
     */
    private function loadSchemaInto(\PDO $pdo): void
    {
        $path = __DIR__ . '/../../../src/Infrastructure/Persistence/sqlite-schema.sql';
        $sql = file_get_contents($path);
        if ($sql === false) {
            $this->fail("Failed to load schema from $path");
        }
        foreach (explode(';', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
    }

    public function testReassignEventsIsNoOpWhenFromEqualsTo(): void
    {
        // Regression: merging (cat_id=1, '') into (cat_id=1, 'admin')
        // collapses to reassignEvents(1, 1, 'admin'). Previously the
        // pre-dedupe DELETE matched itself and wiped every row at
        // cat_id=1. All events must survive.
        $this->repository->save(new Category(1, null, 'Meeting', '#000'));
        $this->repository->save(new Category(1, 'admin', 'Meetings', '#111'));

        $this->insertEntry(100);
        $this->insertEntry(101);
        $this->insertEntry(102);

        $this->repository->assignToEvent(new EventId(100), 'admin', [1]);
        $this->repository->assignToEvent(new EventId(101), 'admin', [1]);
        $this->repository->assignToEvent(new EventId(102), 'admin', [1]);

        $this->repository->reassignEvents(1, 1, 'admin');

        // All three junction rows must still exist.
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM webcal_entry_categories WHERE cat_id = 1 AND cat_owner = 'admin'"
        );
        $this->assertNotFalse($stmt);
        $this->assertSame(3, (int) $stmt->fetchColumn());
    }

    public function testReassignEventsDoesNotTouchOtherUsersRows(): void
    {
        // Regression: the UPDATE used to match on cat_id alone, so
        // merging admin's cat_id=1 into cat_id=2 also moved jdoe's
        // cat_id=1 rows. With the owner filter jdoe's rows must stay
        // where they were.
        $this->repository->save(new Category(1, null, 'Shared', '#000'));
        $this->repository->save(new Category(2, null, 'Target', '#fff'));

        $this->insertEntry(100);
        $this->insertEntry(200);

        $this->repository->assignToEvent(new EventId(100), 'admin', [1]);
        $this->repository->assignToEvent(new EventId(200), 'jdoe', [1]);

        $this->repository->reassignEvents(1, 2, 'admin');

        // admin's row moved to cat_id=2; jdoe's row is still at cat_id=1.
        $stmt = $this->pdo->query(
            "SELECT cat_id FROM webcal_entry_categories WHERE cat_owner = 'admin' AND cal_id = 100"
        );
        $this->assertNotFalse($stmt);
        $this->assertSame(2, (int) $stmt->fetchColumn());

        $stmt = $this->pdo->query(
            "SELECT cat_id FROM webcal_entry_categories WHERE cat_owner = 'jdoe' AND cal_id = 200"
        );
        $this->assertNotFalse($stmt);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    /**
     * @psalm-suppress DeprecatedMethod — the whole point of this test is
     *     to pin the inflated-count behavior of the legacy getEventCount
     *     so the deprecation on it stays honest.
     */
    public function testGetEventCountByOwnerSplitsPerOwner(): void
    {
        // Regression: the legacy getEventCount(1) returns the combined
        // count of every junction row at cat_id=1 regardless of owner,
        // reporting the same inflated number for both a global and a
        // user-owned category sharing that cat_id. The owner-aware
        // variant filters on the junction `cat_owner`, so each owner's
        // assignments are counted separately.
        $this->repository->save(new Category(1, null, 'Global Work', '#000'));
        $this->repository->save(new Category(1, 'admin', 'Personal Work', '#fff'));

        $this->insertEntry(100);
        $this->insertEntry(101);
        $this->insertEntry(102);

        // admin makes two assignments; jdoe makes one.
        $this->repository->assignToEvent(new EventId(100), 'admin', [1]);
        $this->repository->assignToEvent(new EventId(101), 'admin', [1]);
        $this->repository->assignToEvent(new EventId(102), 'jdoe', [1]);

        $this->assertSame(
            2,
            $this->repository->getEventCountByOwner(1, 'admin'),
            'admin\'s assignments counted separately from jdoe\'s'
        );
        $this->assertSame(
            1,
            $this->repository->getEventCountByOwner(1, 'jdoe'),
            'jdoe\'s assignments counted separately from admin\'s'
        );

        // The legacy ambiguous method lumps them all together.
        $this->assertSame(
            3,
            $this->repository->getEventCount(1),
            'legacy getEventCount ignores owner and inflates the count'
        );
    }

    public function testFindByCompositeKeyDisambiguatesSharedCatId(): void
    {
        $this->repository->save(new Category(1, null, 'Global', '#000'));
        $this->repository->save(new Category(1, 'admin', 'Personal', '#fff'));

        $global = $this->repository->findByCompositeKey(1, '');
        $this->assertNotNull($global);
        $this->assertSame('Global', $global->name());
        $this->assertNull($global->owner());

        $personal = $this->repository->findByCompositeKey(1, 'admin');
        $this->assertNotNull($personal);
        $this->assertSame('Personal', $personal->name());
        $this->assertSame('admin', $personal->owner());
    }

    public function testFindByCompositeKeyReturnsNullForUnknownOwner(): void
    {
        $this->repository->save(new Category(1, 'admin', 'Personal', '#fff'));
        $this->assertNull($this->repository->findByCompositeKey(1, 'jdoe'));
        $this->assertNull($this->repository->findByCompositeKey(1, ''));
    }

    public function testDeleteByCompositeKeyRemovesOnlyOneOwner(): void
    {
        $this->repository->save(new Category(1, null, 'Global', '#000'));
        $this->repository->save(new Category(1, 'admin', 'Personal', '#fff'));

        $this->repository->deleteByCompositeKey(1, 'admin');

        // Personal row gone, global row survives.
        $this->assertNull($this->repository->findByCompositeKey(1, 'admin'));
        $survivor = $this->repository->findByCompositeKey(1, '');
        $this->assertNotNull($survivor);
        $this->assertSame('Global', $survivor->name());
    }

    /**
     * @psalm-suppress DeprecatedMethod — intentionally exercises the
     *     deprecated `delete(int)` to pin its destructive behavior.
     */
    public function testLegacyDeleteWipesEveryRowSharingCatId(): void
    {
        // Documents the behavior of the deprecated `delete(int)` method
        // that motivated adding `deleteByCompositeKey`. It removes BOTH
        // the global and the user-owned row at cat_id=1. New code must
        // not call `delete`; this test is a fossil so the deprecation
        // stays honest.
        $this->repository->save(new Category(1, null, 'Global', '#000'));
        $this->repository->save(new Category(1, 'admin', 'Personal', '#fff'));

        $this->repository->delete(1);

        $this->assertNull($this->repository->findByCompositeKey(1, ''));
        $this->assertNull($this->repository->findByCompositeKey(1, 'admin'));
    }

    public function testFindByNameWithOwnerDisambiguatesSharedName(): void
    {
        // Two rows share the name "Work" — one global, one user-owned.
        // findByName must return the right row when the owner is passed.
        $this->repository->save(new Category(1, null, 'Work', '#000'));
        $this->repository->save(new Category(2, 'admin', 'Work', '#fff'));

        $global = $this->repository->findByName('Work', '');
        $this->assertNotNull($global);
        $this->assertSame(1, $global->id());
        $this->assertNull($global->owner());

        $personal = $this->repository->findByName('Work', 'admin');
        $this->assertNotNull($personal);
        $this->assertSame(2, $personal->id());
        $this->assertSame('admin', $personal->owner());

        // Owner with no matching row returns null even if the global
        // row exists.
        $this->assertNull($this->repository->findByName('Work', 'jdoe'));
    }

    public function testAssignToEventPreservesOtherUsersJunctionRows(): void
    {
        // Cross-user isolation on webcal_entry_categories
        // (cal_id, cat_id, cat_order, cat_owner). admin and jdoe each
        // assign their own categories to the same event. Re-assigning
        // admin's list must not delete jdoe's row for the same event.
        $this->repository->save(new Category(1, null, 'Shared', '#000'));
        $this->repository->save(new Category(2, null, 'Other', '#fff'));
        $this->insertEntry(100);

        $this->repository->assignToEvent(new EventId(100), 'admin', [1]);
        $this->repository->assignToEvent(new EventId(100), 'jdoe', [1, 2]);

        // Replace admin's assignments. jdoe's rows must be untouched.
        $this->repository->assignToEvent(new EventId(100), 'admin', [2]);

        $stmt = $this->pdo->query(
            "SELECT cat_id FROM webcal_entry_categories
              WHERE cal_id = 100 AND cat_owner = 'admin'
              ORDER BY cat_order"
        );
        $this->assertNotFalse($stmt);
        $this->assertSame([2], array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));

        $stmt = $this->pdo->query(
            "SELECT cat_id FROM webcal_entry_categories
              WHERE cal_id = 100 AND cat_owner = 'jdoe'
              ORDER BY cat_order"
        );
        $this->assertNotFalse($stmt);
        $this->assertSame([1, 2], array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    private function insertEntry(int $calId): void
    {
        $this->pdo->exec(
            "INSERT INTO webcal_entry (cal_id, cal_name, cal_date, cal_time, cal_duration, cal_create_by, cal_type, cal_access)
             VALUES ($calId, 'Test Event $calId', 20260214, 100000, 60, 'admin', 'E', 'P')"
        );
    }

    // ── Epic 23: tags (flat, global category variant) ──────────────────

    public function testTagFlagRoundTrips(): void
    {
        $this->repository->save(new Category(1, null, 'outdoors', null, true, isTag: true));

        $found = $this->repository->findByCompositeKey(1, '');
        $this->assertNotNull($found);
        $this->assertTrue($found->isTag());

        // And flips off again on update.
        $this->repository->save(new Category(1, null, 'outdoors', null, true, isTag: false));
        $refetched = $this->repository->findByCompositeKey(1, '');
        $this->assertNotNull($refetched);
        $this->assertFalse($refetched->isTag());
    }

    public function testCategoryListingsExcludeTags(): void
    {
        $this->repository->save(new Category(1, null, 'Work', '#0073aa'));
        $this->repository->save(new Category(2, null, 'outdoors', null, true, isTag: true));
        $this->repository->save(new Category(3, 'jdoe', 'Personal', '#00ff00'));

        $globalNames = array_map(static fn (Category $c): string => $c->name(), $this->repository->findAllGlobal());
        $this->assertSame(['Work'], $globalNames);

        $ownedNames = array_map(static fn (Category $c): string => $c->name(), $this->repository->findByOwner('jdoe'));
        $this->assertSame(['Personal'], $ownedNames);

        $this->assertNull($this->repository->findByName('outdoors'), 'findByName is a category lookup, not a tag lookup');
    }

    public function testFindAllTagsReturnsOnlyTagsOrderedByName(): void
    {
        $this->repository->save(new Category(1, null, 'Work', '#0073aa'));
        $this->repository->save(new Category(2, null, 'zebra', null, true, isTag: true));
        $this->repository->save(new Category(3, null, 'apple', null, true, isTag: true));

        $names = array_map(static fn (Category $c): string => $c->name(), $this->repository->findAllTags());

        $this->assertSame(['apple', 'zebra'], $names);
    }

    public function testFindTagByNameIsCaseInsensitiveAndTagOnly(): void
    {
        $this->repository->save(new Category(1, null, 'Music', '#0073aa'));
        $this->repository->save(new Category(2, null, 'music', null, true, isTag: true));

        $tag = $this->repository->findTagByName('MUSIC');
        $this->assertNotNull($tag);
        $this->assertTrue($tag->isTag());
        $this->assertSame(2, $tag->id());

        $this->assertNull($this->repository->findTagByName('Work'));
    }

    public function testTagAndOwnedCategoryCollidingOnCatIdStayIndependent(): void
    {
        // Composite-PK fixture rule: two rows at cat_id=1 with different
        // owners, one of them a tag.
        $this->repository->save(new Category(1, null, 'shared-name', null, true, isTag: true));
        $this->repository->save(new Category(1, 'jdoe', 'Personal', '#00ff00'));

        $this->repository->deleteByCompositeKey(1, '');

        $this->assertNull($this->repository->findTagByName('shared-name'));
        $survivor = $this->repository->findByCompositeKey(1, 'jdoe');
        $this->assertNotNull($survivor);
        $this->assertSame('Personal', $survivor->name());
    }
}
