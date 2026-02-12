<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\Resource;
use WebCalendar\Core\Domain\Repository\ResourceRepositoryInterface;

/**
 * PDO-based implementation of ResourceRepositoryInterface.
 */
final readonly class PdoResourceRepository implements ResourceRepositoryInterface
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findByLogin(string $login): ?Resource
    {
        $stmt = $this->pdo->prepare('SELECT * FROM webcal_nonuser_cals WHERE cal_login = :login');
        $stmt->execute(['login' => $login]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowToResource($row);
    }

    /**
     * @return Resource[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM webcal_nonuser_cals');
        $resources = [];

        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row)) {
                    $resources[] = $this->mapRowToResource($row);
                }
            }
        }

        return $resources;
    }

    /**
     * @return Resource[]
     */
    public function findByAdmin(string $adminLogin): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM webcal_nonuser_cals WHERE cal_admin = :admin');
        $stmt->execute(['admin' => $adminLogin]);
        $resources = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $resources[] = $this->mapRowToResource($row);
            }
        }

        return $resources;
    }

    public function save(Resource $resource): void
    {
        $data = [
            'login' => $resource->login(),
            'name' => $resource->name(),
            'admin' => $resource->admin(),
            'is_public' => $resource->isPublic() ? 'Y' : 'N',
            'url' => $resource->url()
        ];

        $stmt = $this->pdo->prepare('SELECT 1 FROM webcal_nonuser_cals WHERE cal_login = :login');
        $stmt->execute(['login' => $resource->login()]);
        
        if ($stmt->fetch()) {
            $sql = 'UPDATE webcal_nonuser_cals SET 
                    cal_lastname = :name, 
                    cal_admin = :admin, 
                    cal_is_public = :is_public, 
                    cal_url = :url 
                    WHERE cal_login = :login';
        } else {
            $sql = 'INSERT INTO webcal_nonuser_cals (cal_login, cal_lastname, cal_admin, cal_is_public, cal_url)
                    VALUES (:login, :name, :admin, :is_public, :url)';
        }

        $this->pdo->prepare($sql)->execute($data);
    }

    public function delete(string $login): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM webcal_nonuser_cals WHERE cal_login = :login');
        $stmt->execute(['login' => $login]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToResource(array $row): Resource
    {
        $login = is_string($row['cal_login'] ?? null) ? $row['cal_login'] : '';
        $name = is_string($row['cal_lastname'] ?? null) ? $row['cal_lastname'] : '';
        $admin = is_string($row['cal_admin'] ?? null) ? $row['cal_admin'] : '';
        
        return new Resource(
            login: $login,
            name: $name,
            admin: $admin,
            isPublic: ($row['cal_is_public'] ?? 'N') === 'Y',
            url: is_string($row['cal_url'] ?? null) ? $row['cal_url'] : null
        );
    }
}
