<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use PDO;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\UserPreference;
use WebCalendar\Core\Infrastructure\Persistence\PdoUserRepository;

final class PdoUserRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PdoUserRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $this->loadSchema();
        
        $this->repository = new PdoUserRepository($this->pdo);
    }

    private function loadSchema(): void
    {
        $path = __DIR__ . '/../../../src/Infrastructure/Persistence/sqlite-schema.sql';
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException("Failed to load schema from $path");
        }
        $statements = explode(';', $sql);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                $this->pdo->exec($statement);
            }
        }
    }

    public function testSaveAndFindByLogin(): void
    {
        $user = new User(
            login: 'testuser',
            firstName: 'Test',
            lastName: 'User',
            email: 'test@example.com',
            isAdmin: true,
            isEnabled: true
        );

        $this->repository->save($user);

        $foundUser = $this->repository->findByLogin('testuser');

        $this->assertNotNull($foundUser);
        $this->assertSame('testuser', $foundUser->login());
        $this->assertSame('Test', $foundUser->firstName());
        $this->assertSame('User', $foundUser->lastName());
        $this->assertSame('test@example.com', $foundUser->email());
        $this->assertTrue($foundUser->isAdmin());
        $this->assertTrue($foundUser->isEnabled());
    }

    public function testFindAll(): void
    {
        // Initial user from schema + 1 we add
        $user = new User('jdoe', 'John', 'Doe', 'jdoe@example.com', false, true);
        $this->repository->save($user);

        $users = $this->repository->findAll();
        
        // admin (from schema) + jdoe
        $this->assertCount(2, $users);
    }

    public function testDelete(): void
    {
        $user = new User('delete-me', 'Delete', 'Me', 'delete@example.com', false, true);
        $this->repository->save($user);
        $this->assertNotNull($this->repository->findByLogin('delete-me'));

        $this->repository->delete('delete-me');
        $this->assertNull($this->repository->findByLogin('delete-me'));
    }

    public function testPreferences(): void
    {
        $login = 'jdoe';
        $pref = new UserPreference('THEME', 'dark');

        $this->repository->savePreference($login, $pref);
        
        $prefs = $this->repository->getPreferences($login);
        $this->assertCount(1, $prefs);
        $this->assertSame('THEME', $prefs[0]->key());
        $this->assertSame('dark', $prefs[0]->value());
    }
}
