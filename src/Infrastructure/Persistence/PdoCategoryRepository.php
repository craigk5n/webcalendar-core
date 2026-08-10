<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\Repository\CategoryRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;

/**
 * PDO-based implementation of CategoryRepositoryInterface.
 */
final class PdoCategoryRepository implements CategoryRepositoryInterface
{
    use ChunkedInClauseTrait;
    use TransactionalTrait;
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tablePrefix = '',
    ) {
    }

    /**
     * @deprecated Ambiguous when a `cat_id` is shared between a global
     *     (`cat_owner=''`) and a user-owned row. Prefer
     *     {@see findByCompositeKey()}, which reflects the real
     *     `(cat_id, cat_owner)` primary key. This method still returns
     *     whichever row the database engine chooses first.
     */
    public function findById(int $id): ?Category
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tablePrefix}webcal_categories WHERE cat_id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowToCategory($row);
    }

    /**
     * Fetches a single category by its composite primary key
     * `(cat_id, cat_owner)`. Use `''` as the owner to target a global
     * category. Required whenever a numeric `cat_id` can be shared
     * between a global row and a user-owned row.
     */
    public function findByCompositeKey(int $id, string $owner): ?Category
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->tablePrefix}webcal_categories
              WHERE cat_id = :id AND cat_owner = :owner
              LIMIT 1"
        );
        $stmt->execute(['id' => $id, 'owner' => $owner]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowToCategory($row);
    }

    public function findByName(string $name, ?string $owner = null): ?Category
    {
        if ($owner !== null) {
            $stmt = $this->pdo->prepare("SELECT * FROM {$this->tablePrefix}webcal_categories WHERE LOWER(cat_name) = LOWER(:name) AND cat_owner = :owner AND {$this->notATag()} LIMIT 1");
            $stmt->execute(['name' => $name, 'owner' => $owner]);
        } else {
            $stmt = $this->pdo->prepare("SELECT * FROM {$this->tablePrefix}webcal_categories WHERE LOWER(cat_name) = LOWER(:name) AND {$this->notATag()} LIMIT 1");
            $stmt->execute(['name' => $name]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowToCategory($row);
    }

    public function findTagByName(string $name): ?Category
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->tablePrefix}webcal_categories
              WHERE LOWER(cat_name) = LOWER(:name) AND cat_is_tag = 'Y' LIMIT 1"
        );
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->mapRowToCategory($row) : null;
    }

    /**
     * @return Category[] Tags ordered by name.
     */
    public function findAllTags(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM {$this->tablePrefix}webcal_categories WHERE cat_is_tag = 'Y' ORDER BY cat_name"
        );
        $tags = [];
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row)) {
                    $tags[] = $this->mapRowToCategory($row);
                }
            }
        }
        return $tags;
    }

    /**
     * Tag-exclusion predicate. NULL-tolerant so rows predating the
     * `cat_is_tag` column (added Epic 23) keep counting as categories.
     *
     * @param string $alias Table alias to qualify the column with, for the
     *                      per-event reads that join the junction table.
     */
    private function notATag(string $alias = ''): string
    {
        $column = ('' === $alias ? '' : $alias . '.') . 'cat_is_tag';
        return "($column IS NULL OR $column <> 'Y')";
    }

    /**
     * Tag-only predicate — the complement of {@see notATag()}.
     *
     * @param string $alias Table alias to qualify the column with.
     */
    private function isATag(string $alias = ''): string
    {
        $column = ('' === $alias ? '' : $alias . '.') . 'cat_is_tag';
        return "$column = 'Y'";
    }

    public function nextId(): int
    {
        $stmt = $this->pdo->query("SELECT MAX(cat_id) FROM {$this->tablePrefix}webcal_categories");
        $max = $stmt ? (int) $stmt->fetchColumn() : 0;
        return $max + 1;
    }

    /**
     * @return Category[]
     */
    public function findByOwner(?string $owner): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tablePrefix}webcal_categories WHERE cat_owner = :owner AND {$this->notATag()}");
        $stmt->execute(['owner' => $owner ?? '']);
        $categories = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $categories[] = $this->mapRowToCategory($row);
            }
        }

        return $categories;
    }

    /**
     * @return Category[]
     */
    public function findAllGlobal(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM {$this->tablePrefix}webcal_categories WHERE (cat_owner = '' OR cat_owner IS NULL) AND {$this->notATag()}");
        $categories = [];

        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row)) {
                    $categories[] = $this->mapRowToCategory($row);
                }
            }
        }

        return $categories;
    }

    public function save(Category $category): void
    {
        $data = [
            'id' => $category->id(),
            'owner' => $category->owner() ?? '',
            'name' => $category->name(),
            'color' => $category->color(),
            'status' => $category->isEnabled() ? 'A' : 'D',
            'is_tag' => $category->isTag() ? 'Y' : 'N',
        ];

        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->tablePrefix}webcal_categories WHERE cat_id = :id AND cat_owner = :owner");
        $stmt->execute(['id' => $category->id(), 'owner' => $data['owner']]);

        if ($stmt->fetch()) {
            $sql = "UPDATE {$this->tablePrefix}webcal_categories SET
                    cat_name = :name,
                    cat_color = :color,
                    cat_status = :status,
                    cat_is_tag = :is_tag
                    WHERE cat_id = :id AND cat_owner = :owner";
        } else {
            $sql = "INSERT INTO {$this->tablePrefix}webcal_categories (cat_id, cat_owner, cat_name, cat_color, cat_status, cat_is_tag)
                    VALUES (:id, :owner, :name, :color, :status, :is_tag)";
        }

        $this->pdo->prepare($sql)->execute($data);
    }

    public function create(Category $category): void
    {
        $this->save($category);
    }

    /**
     * @deprecated Deletes EVERY category row sharing this `cat_id`,
     *     including a global row and a user-owned row with the same id.
     *     Prefer {@see deleteByCompositeKey()} which honors the real
     *     primary key.
     */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_categories WHERE cat_id = :id");
        $stmt->execute(['id' => $id]);
    }

    /**
     * Deletes a single category by its composite primary key
     * `(cat_id, cat_owner)`. Use `''` to target the global row.
     */
    public function deleteByCompositeKey(int $id, string $owner): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM {$this->tablePrefix}webcal_categories
              WHERE cat_id = :id AND cat_owner = :owner"
        );
        $stmt->execute(['id' => $id, 'owner' => $owner]);
    }

    public function assignToEvent(EventId $eventId, string $userLogin, array $categoryIds): void
    {
        $eventIdValue = $eventId->value();
        
        $this->executeInTransaction(function () use ($eventIdValue, $userLogin, $categoryIds): void {
            $stmt = $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_entry_categories WHERE cal_id = :cal_id AND cat_owner = :owner");
            $stmt->execute(['cal_id' => $eventIdValue, 'owner' => $userLogin]);

            $sql = "INSERT INTO {$this->tablePrefix}webcal_entry_categories (cal_id, cat_id, cat_owner, cat_order)
                    VALUES (:cal_id, :cat_id, :owner, :order)";
            $stmt = $this->pdo->prepare($sql);

            foreach ($categoryIds as $index => $catId) {
                $stmt->execute([
                    'cal_id' => $eventIdValue,
                    'cat_id' => $catId,
                    'owner' => $userLogin,
                    'order' => $index
                ]);
            }
        });
    }

    /**
     * Loads categories assigned to a single event for a given user.
     *
     * Global categories (stored with `cat_owner=''`) are visible to every
     * user, but assignment rows in `webcal_entry_categories` always carry
     * the assigning user's login as `cat_owner`. We therefore match any
     * category whose owner is either the assigner (personal) or empty
     * (global), and prefer the personal version when both exist for the
     * same `cat_id`.
     *
     * @return Category[]
     */
    public function getForEvent(EventId $eventId, string $userLogin): array
    {
        $sql = "SELECT c.*
                FROM {$this->tablePrefix}webcal_categories c
                JOIN {$this->tablePrefix}webcal_entry_categories ec
                  ON c.cat_id = ec.cat_id
                 AND c.cat_owner IN (ec.cat_owner, '')
                WHERE ec.cal_id = :cal_id AND ec.cat_owner = :owner
                ORDER BY ec.cat_order ASC,
                         CASE WHEN c.cat_owner = ec.cat_owner THEN 0 ELSE 1 END";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['cal_id' => $eventId->value(), 'owner' => $userLogin]);
        $categories = [];
        $seen = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $catId = (int) $row['cat_id'];
            if (isset($seen[$catId])) {
                // Personal row was already taken; skip the global duplicate.
                continue;
            }
            $seen[$catId] = true;
            $categories[] = $this->mapRowToCategory($row);
        }

        return $categories;
    }

    /**
     * Tags assigned to a single event, in assignment order.
     *
     * Same global/personal resolution as {@see getForEvent()}; tags are
     * always global, but the junction row still carries the assigning
     * user's login, which is what scopes the read.
     *
     * @return Category[]
     */
    public function getTagsForEvent(EventId $eventId, string $userLogin): array
    {
        $sql = "SELECT c.*
                FROM {$this->tablePrefix}webcal_categories c
                JOIN {$this->tablePrefix}webcal_entry_categories ec
                  ON c.cat_id = ec.cat_id
                 AND c.cat_owner IN (ec.cat_owner, '')
                WHERE ec.cal_id = :cal_id AND ec.cat_owner = :owner
                  AND {$this->isATag('c')}
                ORDER BY ec.cat_order ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['cal_id' => $eventId->value(), 'owner' => $userLogin]);

        $tags = [];
        $seen = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $catId = (int) $row['cat_id'];
            if (isset($seen[$catId])) {
                continue;
            }
            $seen[$catId] = true;
            $tags[] = $this->mapRowToCategory($row);
        }

        return $tags;
    }

    /**
     * @param EventId[] $eventIds
     * @return array<int, list<array{id: int, name: string}>>
     */
    public function getTagsForEventsBatch(array $eventIds, string $userLogin): array
    {
        if (empty($eventIds)) {
            return [];
        }

        $ids = array_map(fn (EventId $id) => $id->value(), $eventIds);
        $map = [];
        // Guards against the global/personal join returning a cat_id twice.
        $seen = [];

        // Chunked for the same placeholder-ceiling reason as
        // getForEventsBatch(); each event id lands in exactly one chunk, so
        // the per-event dedup below is unaffected.
        foreach ($this->chunkForInClause($ids) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $params = array_merge($chunk, [$userLogin]);

            $stmt = $this->pdo->prepare(
                "SELECT ec.cal_id, c.cat_id, c.cat_name
                 FROM {$this->tablePrefix}webcal_entry_categories ec
                 JOIN {$this->tablePrefix}webcal_categories c
                   ON c.cat_id = ec.cat_id
                  AND c.cat_owner IN (ec.cat_owner, '')
                 WHERE ec.cal_id IN ($placeholders) AND ec.cat_owner = ?
                   AND {$this->isATag('c')}
                 ORDER BY ec.cal_id, ec.cat_order ASC"
            );
            $stmt->execute($params);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $eid = (int) $row['cal_id'];
                $catId = (int) $row['cat_id'];
                if (isset($seen[$eid][$catId])) {
                    continue;
                }
                $seen[$eid][$catId] = true;
                $map[$eid][] = [
                    'id' => $catId,
                    'name' => (string) $row['cat_name'],
                ];
            }
        }

        return $map;
    }

    public function getForEventsBatch(array $eventIds, string $userLogin): array
    {
        if (empty($eventIds)) {
            return [];
        }

        $ids = array_map(fn(EventId $id) => $id->value(), $eventIds);

        $map = [];

        // Chunked so the placeholder count stays under the backend's
        // per-statement ceiling on large batches (see ChunkedInClauseTrait).
        // Safe here: each event id lands in exactly one chunk, so the
        // per-event first-row-wins dedup below is unaffected by chunking.
        foreach ($this->chunkForInClause($ids) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $params = array_merge($chunk, [$userLogin]);

            // See getForEvent() for the global/personal category matching
            // rationale. The `CASE WHEN ... THEN 0 ELSE 1` ordering makes the
            // personal version sort before the global one so the per-event
            // dedup loop below deterministically keeps the personal match.
            $stmt = $this->pdo->prepare(
                "SELECT ec.cal_id, c.cat_id, c.cat_color
                 FROM {$this->tablePrefix}webcal_entry_categories ec
                 JOIN {$this->tablePrefix}webcal_categories c
                   ON c.cat_id = ec.cat_id
                  AND c.cat_owner IN (ec.cat_owner, '')
                 WHERE ec.cal_id IN ($placeholders) AND ec.cat_owner = ?
                   AND {$this->notATag('c')}
                 ORDER BY ec.cal_id, ec.cat_order ASC,
                          CASE WHEN c.cat_owner = ec.cat_owner THEN 0 ELSE 1 END"
            );
            $stmt->execute($params);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $eid = (int) $row['cal_id'];
                if (!isset($map[$eid])) {
                    $map[$eid] = [
                        'id' => (int) $row['cat_id'],
                        'color' => $row['cat_color'],
                    ];
                }
            }
        }

        return $map;
    }

    /**
     * @deprecated Inflates counts when a `cat_id` is shared by a global
     *     and a user-owned category. Prefer
     *     {@see getEventCountByOwner()}, which resolves the ambiguity
     *     by joining through the category table.
     */
    public function getEventCount(int $catId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT cal_id) FROM {$this->tablePrefix}webcal_entry_categories WHERE cat_id = :id"
        );
        $stmt->execute(['id' => $catId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Counts distinct events assigned to a specific category, matched
     * by the composite primary key `(cat_id, cat_owner)`.
     *
     * Filters the junction table on `ec.cat_owner`, which is populated
     * with the assigning user's login by {@see assignToEvent()}. So the
     * semantic is "distinct events where this user recorded this cat_id
     * against themselves." This is what the admin Categories panel needs
     * to answer "does this category have any uses before I delete it?"
     * without conflating a global and a personal category that share a
     * numeric id.
     *
     * Pass `''` for a truly unused global row (one where no user has
     * written a junction entry under the empty owner); personal rows
     * resolve against their owner's login.
     */
    public function getEventCountByOwner(int $catId, string $catOwner): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT cal_id)
               FROM {$this->tablePrefix}webcal_entry_categories
              WHERE cat_id = :id AND cat_owner = :owner"
        );
        $stmt->execute(['id' => $catId, 'owner' => $catOwner]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Reassigns a user's junction rows from one category to another,
     * deduplicating any rows that would become duplicates after the
     * update.
     *
     * The `$userLogin` is matched against `webcal_entry_categories.cat_owner`,
     * which is populated with the assigning user's login (see
     * {@see assignToEvent()}). This scopes the operation to rows the
     * caller actually owns, so merging `cat_id=1` for user `admin` can
     * never touch rows belonging to `jdoe`, even if `jdoe` also uses
     * `cat_id=1`.
     *
     * Guards against the same-id self-merge case (`(1, '') → (1, 'admin')`
     * collapses to `(1, 1)` at the junction level and would previously
     * cause the pre-dedupe DELETE to match itself and destroy every
     * junction row at that cat_id). The whole operation runs inside a
     * transaction so a partial failure cannot leave the junction table
     * half-destroyed.
     */
    public function reassignEvents(int $fromCatId, int $toCatId, string $userLogin): void
    {
        // Self-merge: the UPDATE below would be a no-op, but the
        // pre-DELETE would match the source against itself and wipe
        // every junction row at this cat_id. Bail out first.
        if ($fromCatId === $toCatId) {
            return;
        }

        $this->executeInTransaction(function () use ($fromCatId, $toCatId, $userLogin): void {
            // Pre-delete source rows that would collide with an existing
            // target row after the UPDATE (PK is
            // (cal_id, cat_id, cat_order, cat_owner) so identical
            // cal_id + cat_order + same owner would violate it).
            //
            // Each named placeholder appears exactly once. Reusing a
            // named placeholder (e.g. two `:user` references) is legal
            // in PDO's emulated-prepares mode and in SQLite, but breaks
            // with SQLSTATE[HY093] under `PDO::ATTR_EMULATE_PREPARES=false`
            // on MySQL/PostgreSQL native prepares. Keep the (from/to)
            // split so downstream consumers that harden their PDO config
            // don't hit a non-portable-looking bug from this library.
            $stmt = $this->pdo->prepare(
                "DELETE FROM {$this->tablePrefix}webcal_entry_categories
                 WHERE cat_id = :from_id
                   AND cat_owner = :user_from
                   AND cal_id IN (
                     SELECT cal_id FROM (
                       SELECT cal_id FROM {$this->tablePrefix}webcal_entry_categories
                        WHERE cat_id = :to_id AND cat_owner = :user_to
                     ) AS existing
                   )"
            );
            $stmt->execute([
                'from_id' => $fromCatId,
                'to_id' => $toCatId,
                'user_from' => $userLogin,
                'user_to' => $userLogin,
            ]);

            // Move the caller's remaining rows to the target category.
            // Filtering on cat_owner prevents the UPDATE from sweeping
            // other users' rows that happen to share the same cat_id.
            $stmt = $this->pdo->prepare(
                "UPDATE {$this->tablePrefix}webcal_entry_categories
                    SET cat_id = :to_id
                  WHERE cat_id = :from_id AND cat_owner = :user"
            );
            $stmt->execute([
                'to_id' => $toCatId,
                'from_id' => $fromCatId,
                'user' => $userLogin,
            ]);
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToCategory(array $row): Category
    {
        $owner = is_string($row['cat_owner'] ?? null) ? $row['cat_owner'] : '';
        $id = is_numeric($row['cat_id'] ?? null) ? (int)$row['cat_id'] : 0;
        $name = is_string($row['cat_name'] ?? null) ? $row['cat_name'] : '';
        $color = is_string($row['cat_color'] ?? null) ? $row['cat_color'] : null;
        
        return new Category(
            id: $id,
            owner: $owner === '' ? null : $owner,
            name: $name,
            color: $color,
            enabled: ($row['cat_status'] ?? 'A') === 'A',
            isTag: ($row['cat_is_tag'] ?? 'N') === 'Y',
        );
    }

}
