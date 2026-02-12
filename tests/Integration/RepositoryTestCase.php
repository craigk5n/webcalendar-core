<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

abstract class RepositoryTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $dbType = getenv('DB_TYPE') ?: 'sqlite';
        $dbUrl = getenv('DB_URL') ?: 'sqlite::memory:';
        $dbUser = getenv('DB_USER') ?: null;
        $dbPass = getenv('DB_PASS') ?: null;

        $this->pdo = new PDO($dbUrl, $dbUser, $dbPass);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if (!$this->isSchemaLoaded()) {
            $this->loadSchema($dbType);
        }

        $this->cleanDatabase($dbType);
    }

    private function isSchemaLoaded(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM webcal_user LIMIT 1');
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    private function loadSchema(string $dbType): void
    {
        $schemaFile = match ($dbType) {
            'mysql' => 'mysql-schema.sql',
            'postgres', 'postgresql' => 'postgresql-schema.sql',
            default => 'sqlite-schema.sql',
        };

        $path = __DIR__ . '/../../src/Infrastructure/Persistence/' . $schemaFile;
        $sql = file_get_contents($path);
        
        if ($sql === false) {
            throw new \RuntimeException("Failed to load schema from $path");
        }

        $statements = explode(';', $sql);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                try {
                    $this->pdo->exec($statement);
                } catch (\PDOException $e) {
                    // Ignore common "already exists" errors
                    $msg = $e->getMessage();
                    if (!str_contains($msg, 'already exists') && 
                        !str_contains($msg, 'exists already') &&
                        !str_contains($msg, 'Duplicate entry') &&
                        !str_contains($msg, 'Duplicate key')) {
                        throw $e;
                    }
                }
            }
        }
    }

    private function cleanDatabase(string $dbType): void
    {
        $tables = [
            'webcal_entry_user',
            'webcal_entry_repeats',
            'webcal_entry_repeats_not',
            'webcal_entry',
            'webcal_user_pref',
            'webcal_user'
        ];

        if ($dbType === 'mysql') {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tables as $table) {
                $this->pdo->exec("TRUNCATE TABLE $table");
            }
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } elseif ($dbType === 'sqlite') {
            foreach ($tables as $table) {
                $this->pdo->exec("DELETE FROM $table");
            }
        } else {
            foreach ($tables as $table) {
                // For Postgres
                $this->pdo->exec("TRUNCATE TABLE " . implode(',', $tables) . " CASCADE");
                break; // One command is enough for all tables in Postgres with CASCADE
            }
        }

        // Re-insert admin user
        $this->pdo->exec("INSERT INTO webcal_user (cal_login, cal_passwd, cal_lastname, cal_firstname, cal_is_admin, cal_email)
                          VALUES ('admin', 'hash', 'Admin', 'Default', 'Y', 'admin@example.com')");
    }
}
