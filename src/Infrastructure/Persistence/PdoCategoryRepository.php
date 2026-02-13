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
final readonly class PdoCategoryRepository implements CategoryRepositoryInterface
{
    public function __construct(
        private PDO $pdo,
        private string $tablePrefix = '',
    ) {
    }

    public function findById(int $id): ?Category
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tablePrefix}webcal_categories WHERE cat_id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowToCategory($row);
    }

    /**
     * @return Category[]
     */
    public function findByOwner(?string $owner): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tablePrefix}webcal_categories WHERE cat_owner = :owner");
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
        $stmt = $this->pdo->query("SELECT * FROM {$this->tablePrefix}webcal_categories WHERE cat_owner = '' OR cat_owner IS NULL");
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
            'status' => $category->isEnabled() ? 'A' : 'D'
        ];

        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->tablePrefix}webcal_categories WHERE cat_id = :id AND cat_owner = :owner");
        $stmt->execute(['id' => $category->id(), 'owner' => $data['owner']]);
        
        if ($stmt->fetch()) {
            $sql = "UPDATE {$this->tablePrefix}webcal_categories SET 
                    cat_name = :name, 
                    cat_color = :color, 
                    cat_status = :status 
                    WHERE cat_id = :id AND cat_owner = :owner";
        } else {
            $sql = "INSERT INTO {$this->tablePrefix}webcal_categories (cat_id, cat_owner, cat_name, cat_color, cat_status)
                    VALUES (:id, :owner, :name, :color, :status)";
        }

        $this->pdo->prepare($sql)->execute($data);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_categories WHERE cat_id = :id");
        $stmt->execute(['id' => $id]);
    }

    public function assignToEvent(EventId $eventId, string $userLogin, array $categoryIds): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_entry_categories WHERE cal_id = :cal_id AND cat_owner = :owner");
        $stmt->execute(['cal_id' => $eventId->value(), 'owner' => $userLogin]);

        $sql = "INSERT INTO {$this->tablePrefix}webcal_entry_categories (cal_id, cat_id, cat_owner, cat_order)
                VALUES (:cal_id, :cat_id, :owner, :order)";
        $stmt = $this->pdo->prepare($sql);

        foreach ($categoryIds as $index => $catId) {
            $stmt->execute([
                'cal_id' => $eventId->value(),
                'cat_id' => $catId,
                'owner' => $userLogin,
                'order' => $index
            ]);
        }
    }

    /**
     * @return Category[]
     */
    public function getForEvent(EventId $eventId, string $userLogin): array
    {
        $sql = "SELECT c.* FROM {$this->tablePrefix}webcal_categories c
                JOIN {$this->tablePrefix}webcal_entry_categories ec ON c.cat_id = ec.cat_id
                WHERE ec.cal_id = :cal_id AND ec.cat_owner = :owner";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['cal_id' => $eventId->value(), 'owner' => $userLogin]);
        $categories = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $categories[] = $this->mapRowToCategory($row);
            }
        }

        return $categories;
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
            enabled: ($row['cat_status'] ?? 'A') === 'A'
        );
    }
}
