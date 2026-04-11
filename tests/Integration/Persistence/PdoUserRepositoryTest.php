<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\UserPreference;
use WebCalendar\Core\Infrastructure\Persistence\PdoUserRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

final class PdoUserRepositoryTest extends RepositoryTestCase
{
    private PdoUserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PdoUserRepository($this->pdo);
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

    // -- Composite-key and cross-scope isolation regression coverage ------
    //
    // UserRepository.delete() cascades across more than a dozen tables
    // (webcal_user_pref, webcal_entry_user, webcal_access_user, etc.).
    // Several of those have composite primary keys where cal_login is
    // only half the key. These tests pin two properties:
    //   1. All rows for the target user are actually removed.
    //   2. No rows for *other* users are removed.
    //
    // Similarly, savePreference's composite key (cal_login, cal_setting)
    // means we must verify that updating (jdoe, THEME) never touches
    // (admin, THEME).

    public function testDeleteRemovesTargetUserPreferencesAndParticipation(): void
    {
        // Seed jdoe with a pref row and a participation row, then prove
        // both are gone after delete().
        $this->repository->save(new User('jdoe', 'John', 'Doe', 'j@example.com', false, true));
        $this->repository->savePreference('jdoe', new UserPreference('THEME', 'dark'));
        $this->pdo->exec(
            "INSERT INTO webcal_entry (cal_id, cal_name, cal_date, cal_time, cal_duration, cal_create_by, cal_type, cal_access)
             VALUES (100, 'Shared', 20260410, 100000, 60, 'admin', 'E', 'P')"
        );
        $this->pdo->exec(
            "INSERT INTO webcal_entry_user (cal_id, cal_login, cal_status) VALUES (100, 'jdoe', 'A')"
        );

        $this->repository->delete('jdoe');

        $this->assertNull($this->repository->findByLogin('jdoe'));
        $this->assertSame([], $this->repository->getPreferences('jdoe'));

        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM webcal_entry_user WHERE cal_login = 'jdoe'"
        );
        $this->assertNotFalse($stmt);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'jdoe participation rows must be removed');
    }

    public function testDeletePreservesOtherUsersRows(): void
    {
        // Cross-user isolation: deleting jdoe must not touch admin's
        // rows in any of the cascade tables, even when admin and jdoe
        // both have rows in a composite-PK table like webcal_user_pref
        // (cal_login, cal_setting) or webcal_entry_user (cal_id, cal_login).
        $this->repository->save(new User('jdoe', 'John', 'Doe', 'j@example.com', false, true));

        // Both users have a THEME preference row — composite PK collision
        // on `cal_setting='THEME'`. admin's row must survive.
        $this->repository->savePreference('admin', new UserPreference('THEME', 'light'));
        $this->repository->savePreference('jdoe', new UserPreference('THEME', 'dark'));

        // Both users participate in the same event — composite PK collision
        // on `cal_id=100`. admin's row must survive.
        $this->pdo->exec(
            "INSERT INTO webcal_entry (cal_id, cal_name, cal_date, cal_time, cal_duration, cal_create_by, cal_type, cal_access)
             VALUES (100, 'Shared', 20260410, 100000, 60, 'admin', 'E', 'P')"
        );
        $this->pdo->exec(
            "INSERT INTO webcal_entry_user (cal_id, cal_login, cal_status) VALUES (100, 'admin', 'A')"
        );
        $this->pdo->exec(
            "INSERT INTO webcal_entry_user (cal_id, cal_login, cal_status) VALUES (100, 'jdoe', 'A')"
        );

        // Both users have an assistant/boss relationship row — composite
        // PK (cal_boss, cal_assistant). admin's row must survive.
        $this->pdo->exec("INSERT INTO webcal_asst (cal_boss, cal_assistant) VALUES ('admin', 'other')");
        $this->pdo->exec("INSERT INTO webcal_asst (cal_boss, cal_assistant) VALUES ('jdoe',  'other')");

        // Both users have an access grant — composite PK
        // (cal_login, cal_other_user). admin's row must survive.
        $this->pdo->exec(
            "INSERT INTO webcal_access_user (cal_login, cal_other_user, cal_can_view) VALUES ('admin', 'other', 1)"
        );
        $this->pdo->exec(
            "INSERT INTO webcal_access_user (cal_login, cal_other_user, cal_can_view) VALUES ('jdoe',  'other', 1)"
        );

        $this->repository->delete('jdoe');

        // admin's preference row survives.
        $adminPrefs = $this->repository->getPreferences('admin');
        $this->assertCount(1, $adminPrefs);
        $this->assertSame('light', $adminPrefs[0]->value());

        // admin's participation row survives.
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM webcal_entry_user WHERE cal_login = 'admin' AND cal_id = 100"
        );
        $this->assertNotFalse($stmt);
        $this->assertSame(1, (int) $stmt->fetchColumn(), "admin's participation must survive");

        // admin's assistant row survives.
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM webcal_asst WHERE cal_boss = 'admin'"
        );
        $this->assertNotFalse($stmt);
        $this->assertSame(1, (int) $stmt->fetchColumn(), "admin's assistant row must survive");

        // admin's access grant survives.
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM webcal_access_user WHERE cal_login = 'admin'"
        );
        $this->assertNotFalse($stmt);
        $this->assertSame(1, (int) $stmt->fetchColumn(), "admin's access_user row must survive");
    }

    public function testDeleteIsNoOpForUnknownLogin(): void
    {
        // Self-input / degenerate case: deleting a login that does not
        // exist must not raise and must not touch other rows.
        $this->repository->save(new User('jdoe', 'John', 'Doe', 'j@example.com', false, true));
        $this->repository->savePreference('jdoe', new UserPreference('THEME', 'dark'));

        $this->repository->delete('ghost');

        $this->assertNotNull($this->repository->findByLogin('jdoe'));
        $this->assertCount(1, $this->repository->getPreferences('jdoe'));
    }

    public function testSavePreferenceUpdatesExistingRowIdempotent(): void
    {
        // Regression pin for the update branch of savePreference.
        // Calling it twice with the same (login, setting) must UPDATE
        // the second time, not INSERT a duplicate (which would violate
        // the composite PK and raise).
        $this->repository->savePreference('jdoe', new UserPreference('THEME', 'dark'));
        $this->repository->savePreference('jdoe', new UserPreference('THEME', 'light'));

        $prefs = $this->repository->getPreferences('jdoe');
        $this->assertCount(1, $prefs);
        $this->assertSame('light', $prefs[0]->value());
    }

    public function testSavePreferenceDoesNotTouchOtherUsersSameSetting(): void
    {
        // Cross-user isolation on a composite-PK write. admin and jdoe
        // each have a THEME pref. Updating jdoe's THEME must not
        // overwrite admin's THEME.
        $this->repository->savePreference('admin', new UserPreference('THEME', 'light'));
        $this->repository->savePreference('jdoe', new UserPreference('THEME', 'dark'));

        $this->repository->savePreference('jdoe', new UserPreference('THEME', 'solarized'));

        $adminPrefs = $this->repository->getPreferences('admin');
        $jdoePrefs = $this->repository->getPreferences('jdoe');

        $this->assertSame('light', $adminPrefs[0]->value(), "admin's THEME must be untouched");
        $this->assertSame('solarized', $jdoePrefs[0]->value());
    }
}
