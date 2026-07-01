<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

/**
 * PDO-based implementation of UserRepositoryInterface.
 */
final class PdoUserRepository implements UserRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tablePrefix = '',
    ) {
    }

    public function findByLogin(string $login): ?User
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tablePrefix}webcal_user WHERE cal_login = :login");
        $stmt->execute(['login' => $login]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowToUser($row);
    }

    /**
     * @return User[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM {$this->tablePrefix}webcal_user");
        $users = [];

        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row)) {
                    $users[] = $this->mapRowToUser($row);
                }
            }
        }

        return $users;
    }

    /**
     * @return User[]
     */
    public function findAllEnabled(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM {$this->tablePrefix}webcal_user WHERE cal_enabled = 'Y'");
        $users = [];

        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row)) {
                    $users[] = $this->mapRowToUser($row);
                }
            }
        }

        return $users;
    }

    public function save(User $user): void
    {
        $data = [
            'login' => $user->login(),
            'lastname' => $user->lastName(),
            'firstname' => $user->firstName(),
            'is_admin' => $user->isAdmin() ? 'Y' : 'N',
            'email' => $user->email(),
            'enabled' => $user->isEnabled() ? 'Y' : 'N',
        ];

        $existing = $this->findByLogin($user->login());

        if ($existing) {
            $sql = "UPDATE {$this->tablePrefix}webcal_user SET
                    cal_lastname = :lastname,
                    cal_firstname = :firstname,
                    cal_is_admin = :is_admin,
                    cal_email = :email,
                    cal_enabled = :enabled
                    WHERE cal_login = :login";
        } else {
            $sql = "INSERT INTO {$this->tablePrefix}webcal_user
                    (cal_login, cal_lastname, cal_firstname, cal_is_admin, cal_email, cal_enabled)
                    VALUES (:login, :lastname, :firstname, :is_admin, :email, :enabled)";
        }

        $this->pdo->prepare($sql)->execute($data);
    }

    public function delete(string $login): void
    {
        $this->pdo->beginTransaction();

        try {
            $tables = [
                'webcal_user_pref' => ['cal_login'],
                'webcal_user_layers' => ['cal_login', 'cal_layeruser'],
                'webcal_entry_user' => ['cal_login'],
                'webcal_group_user' => ['cal_login'],
                'webcal_access_user' => ['cal_login', 'cal_other_user'],
                'webcal_access_function' => ['cal_login'],
                'webcal_user_template' => ['cal_login'],
                'webcal_blob' => ['cal_login'],
                'webcal_asst' => ['cal_boss', 'cal_assistant'],
                'webcal_view_user' => ['cal_login'],
                'webcal_import' => ['cal_login'],
                'webcal_import_data' => ['cal_login'],
                'webcal_nonuser_cals' => ['cal_admin'],
                'webcal_report' => ['cal_login'],
                'webcal_entry_log' => ['cal_login'],
            ];

            foreach ($tables as $table => $columns) {
                foreach ($columns as $column) {
                    $sql = "DELETE FROM {$this->tablePrefix}{$table} WHERE {$column} = :login";
                    $this->pdo->prepare($sql)->execute(['login' => $login]);
                }
            }

            $stmt = $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_user WHERE cal_login = :login");
            $stmt->execute(['login' => $login]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return UserPreference[]
     */
    public function getPreferences(string $login): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tablePrefix}webcal_user_pref WHERE cal_login = :login");
        $stmt->execute(['login' => $login]);
        $prefs = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $key = isset($row['cal_setting']) && is_string($row['cal_setting']) ? $row['cal_setting'] : '';
                $value = isset($row['cal_value']) && is_string($row['cal_value']) ? $row['cal_value'] : '';
                if ($key !== '') {
                    $prefs[] = new UserPreference($key, $value);
                }
            }
        }

        return $prefs;
    }

    public function getPasswordHash(string $login): ?string
    {
        $stmt = $this->pdo->prepare("SELECT cal_passwd FROM {$this->tablePrefix}webcal_user WHERE cal_login = :login");
        $stmt->execute(['login' => $login]);
        $hash = $stmt->fetchColumn();

        return is_string($hash) ? $hash : null;
    }

    public function setPassword(string $login, string $hash): void
    {
        $stmt = $this->pdo->prepare("UPDATE {$this->tablePrefix}webcal_user SET cal_passwd = :hash WHERE cal_login = :login");
        $stmt->execute(['login' => $login, 'hash' => $hash]);
    }

    public function savePreference(string $login, UserPreference $preference): void
    {
        $data = [
            'login' => $login,
            'setting' => $preference->key(),
            'value' => $preference->value(),
        ];

        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->tablePrefix}webcal_user_pref WHERE cal_login = :login AND cal_setting = :setting");
        $stmt->execute(['login' => $login, 'setting' => $preference->key()]);
        
        if ($stmt->fetch()) {
            $sql = "UPDATE {$this->tablePrefix}webcal_user_pref SET cal_value = :value
                    WHERE cal_login = :login AND cal_setting = :setting";
        } else {
            $sql = "INSERT INTO {$this->tablePrefix}webcal_user_pref (cal_login, cal_setting, cal_value)
                    VALUES (:login, :setting, :value)";
        }
        
        $this->pdo->prepare($sql)->execute($data);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToUser(array $row): User
    {
        $login = is_string($row['cal_login'] ?? null) ? $row['cal_login'] : '';
        $firstName = is_string($row['cal_firstname'] ?? null) ? $row['cal_firstname'] : '';
        $lastName = is_string($row['cal_lastname'] ?? null) ? $row['cal_lastname'] : '';
        $email = is_string($row['cal_email'] ?? null) ? $row['cal_email'] : '';
        
        return new User(
            login: $login,
            firstName: $firstName,
            lastName: $lastName,
            email: $email,
            isAdmin: ($row['cal_is_admin'] ?? 'N') === 'Y',
            isEnabled: ($row['cal_enabled'] ?? 'Y') === 'Y'
        );
    }
}
