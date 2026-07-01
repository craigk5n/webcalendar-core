<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Repository\PermissionRepositoryInterface;

/**
 * PDO-based implementation of PermissionRepositoryInterface.
 */
final class PdoPermissionRepository implements PermissionRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tablePrefix = '',
    ) {
    }

    public function getPermissions(string $login): string
    {
        $stmt = $this->pdo->prepare("SELECT cal_permissions FROM {$this->tablePrefix}webcal_access_function WHERE cal_login = :login");
        $stmt->execute(['login' => $login]);
        $perms = $stmt->fetchColumn();

        return is_string($perms) ? $perms : str_repeat('N', 64);
    }
}
