<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\View;
use WebCalendar\Core\Domain\Repository\ViewRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\ViewType;

/**
 * PDO-based implementation of ViewRepositoryInterface.
 */
final readonly class PdoViewRepository implements ViewRepositoryInterface
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findById(int $id): ?View
    {
        $stmt = $this->pdo->prepare('SELECT * FROM webcal_view WHERE cal_view_id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowToView($row);
    }

    /**
     * @return View[]
     */
    public function findByOwner(string $owner): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM webcal_view WHERE cal_owner = :owner');
        $stmt->execute(['owner' => $owner]);
        $views = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $views[] = $this->mapRowToView($row);
            }
        }

        return $views;
    }

    /**
     * @return View[]
     */
    public function findAllGlobal(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM webcal_view WHERE cal_is_global = 'Y'");
        $views = [];

        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row)) {
                    $views[] = $this->mapRowToView($row);
                }
            }
        }

        return $views;
    }

    public function save(View $view): void
    {
        $data = [
            'id' => $view->id(),
            'owner' => $view->owner(),
            'name' => $view->name(),
            'type' => $view->type()->value,
            'global' => $view->isGlobal() ? 'Y' : 'N'
        ];

        $stmt = $this->pdo->prepare('SELECT 1 FROM webcal_view WHERE cal_view_id = :id');
        $stmt->execute(['id' => $view->id()]);
        
        if ($stmt->fetch()) {
            $sql = 'UPDATE webcal_view SET 
                    cal_owner = :owner, 
                    cal_name = :name, 
                    cal_view_type = :type, 
                    cal_is_global = :global 
                    WHERE cal_view_id = :id';
        } else {
            $sql = 'INSERT INTO webcal_view (cal_view_id, cal_owner, cal_name, cal_view_type, cal_is_global)
                    VALUES (:id, :owner, :name, :type, :global)';
        }

        $this->pdo->prepare($sql)->execute($data);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM webcal_view_user WHERE cal_view_id = :id')
            ->execute(['id' => $id]);
        $this->pdo->prepare('DELETE FROM webcal_view WHERE cal_view_id = :id')
            ->execute(['id' => $id]);
    }

    /**
     * @return string[]
     */
    public function getUsers(int $viewId): array
    {
        $stmt = $this->pdo->prepare('SELECT cal_login FROM webcal_view_user WHERE cal_view_id = :id');
        $stmt->execute(['id' => $viewId]);
        /** @var string[] $logins */
        $logins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $logins;
    }

    public function setUsers(int $viewId, array $logins): void
    {
        $this->pdo->prepare('DELETE FROM webcal_view_user WHERE cal_view_id = :id')
            ->execute(['id' => $viewId]);

        $stmt = $this->pdo->prepare('INSERT INTO webcal_view_user (cal_view_id, cal_login) VALUES (:id, :login)');
        foreach ($logins as $login) {
            $stmt->execute(['id' => $viewId, 'login' => $login]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToView(array $row): View
    {
        $id = is_numeric($row['cal_view_id'] ?? null) ? (int)$row['cal_view_id'] : 0;
        $owner = is_string($row['cal_owner'] ?? null) ? $row['cal_owner'] : '';
        $name = is_string($row['cal_name'] ?? null) ? $row['cal_name'] : '';
        $type = is_string($row['cal_view_type'] ?? null) ? $row['cal_view_type'] : 'M';

        return new View(
            id: $id,
            owner: $owner,
            name: $name,
            type: ViewType::from($type),
            isGlobal: ($row['cal_is_global'] ?? 'N') === 'Y'
        );
    }
}
