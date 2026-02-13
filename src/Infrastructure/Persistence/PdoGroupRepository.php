<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\Group;
use WebCalendar\Core\Domain\Repository\GroupRepositoryInterface;

/**
 * PDO-based implementation of GroupRepositoryInterface.
 */
final readonly class PdoGroupRepository implements GroupRepositoryInterface
{
    public function __construct(
        private PDO $pdo,
        private string $tablePrefix = '',
    ) {
    }

    public function findById(int $id): ?Group
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tablePrefix}webcal_group WHERE cal_group_id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowToGroup($row);
    }

    /**
     * @return Group[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM {$this->tablePrefix}webcal_group");
        $groups = [];

        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row)) {
                    $groups[] = $this->mapRowToGroup($row);
                }
            }
        }

        return $groups;
    }

    /**
     * @return Group[]
     */
    public function findByOwner(string $owner): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tablePrefix}webcal_group WHERE cal_owner = :owner");
        $stmt->execute(['owner' => $owner]);
        $groups = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $groups[] = $this->mapRowToGroup($row);
            }
        }

        return $groups;
    }

    public function save(Group $group): void
    {
        $data = [
            'id' => $group->id(),
            'owner' => $group->owner(),
            'name' => $group->name(),
            'last_update' => (int)$group->lastUpdate()->format('Ymd')
        ];

        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->tablePrefix}webcal_group WHERE cal_group_id = :id");
        $stmt->execute(['id' => $group->id()]);
        
        if ($stmt->fetch()) {
            $sql = "UPDATE {$this->tablePrefix}webcal_group SET
                    cal_owner = :owner,
                    cal_name = :name,
                    cal_last_update = :last_update
                    WHERE cal_group_id = :id";
        } else {
            $sql = "INSERT INTO {$this->tablePrefix}webcal_group (cal_group_id, cal_owner, cal_name, cal_last_update)
                    VALUES (:id, :owner, :name, :last_update)";
        }

        $this->pdo->prepare($sql)->execute($data);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_group_user WHERE cal_group_id = :id")
            ->execute(['id' => $id]);
        $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_group WHERE cal_group_id = :id")
            ->execute(['id' => $id]);
    }

    /**
     * @return string[]
     */
    public function getMembers(int $groupId): array
    {
        $stmt = $this->pdo->prepare("SELECT cal_login FROM {$this->tablePrefix}webcal_group_user WHERE cal_group_id = :id");
        $stmt->execute(['id' => $groupId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function addMember(int $groupId, string $login): void
    {
        $sql = "INSERT INTO {$this->tablePrefix}webcal_group_user (cal_group_id, cal_login) VALUES (:id, :login)";
        $this->pdo->prepare($sql)->execute(['id' => $groupId, 'login' => $login]);
    }

    public function removeMember(int $groupId, string $login): void
    {
        $sql = "DELETE FROM {$this->tablePrefix}webcal_group_user WHERE cal_group_id = :id AND cal_login = :login";
        $this->pdo->prepare($sql)->execute(['id' => $groupId, 'login' => $login]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToGroup(array $row): Group
    {
        $rawDate = $row['cal_last_update'] ?? '';
        $dateStr = is_scalar($rawDate) ? (string)$rawDate : '';
        $date = \DateTimeImmutable::createFromFormat('Ymd', $dateStr);
        if ($date === false) {
            $date = new \DateTimeImmutable();
        }

        $id = is_numeric($row['cal_group_id'] ?? null) ? (int)$row['cal_group_id'] : 0;
        $owner = is_string($row['cal_owner'] ?? null) ? $row['cal_owner'] : '';
        $name = is_string($row['cal_name'] ?? null) ? $row['cal_name'] : '';

        return new Group(
            id: $id,
            owner: $owner,
            name: $name,
            lastUpdate: $date
        );
    }
}
