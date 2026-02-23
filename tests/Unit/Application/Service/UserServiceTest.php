<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\UserService;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;

final class UserServiceTest extends TestCase
{
    /** @var UserRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $userRepository;
    private UserService $userService;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->userService = new UserService($this->userRepository);
    }

    private function createUser(string $login, bool $isAdmin = false): User
    {
        return new User($login, 'First', 'Last', $login . '@example.com', $isAdmin, true);
    }

    public function testGetUserByLogin(): void
    {
        $user = $this->createUser('jdoe');
        
        $this->userRepository->expects($this->once())
            ->method('findByLogin')
            ->with('jdoe')
            ->willReturn($user);

        $result = $this->userService->getUserByLogin('jdoe');
        $this->assertSame($user, $result);
    }

    public function testAdminCanCreateUser(): void
    {
        $newUser = $this->createUser('newuser');
        $admin = $this->createUser('admin', isAdmin: true);
        
        $this->userRepository->expects($this->once())
            ->method('save')
            ->with($newUser);

        $this->userService->createUser($newUser, $admin);
    }

    public function testNonAdminCannotCreateUser(): void
    {
        $newUser = $this->createUser('newuser');
        $actor = $this->createUser('actor');
        
        $this->userRepository->expects($this->never())
            ->method('save');

        $this->expectException(AuthorizationException::class);
        $this->userService->createUser($newUser, $actor);
    }

    public function testAdminCanUpdateAnyUser(): void
    {
        $targetUser = $this->createUser('target');
        $admin = $this->createUser('admin', isAdmin: true);
        
        $this->userRepository->expects($this->once())
            ->method('save')
            ->with($targetUser);

        $this->userService->updateUser($targetUser, $admin);
    }

    public function testUserCanUpdateSelf(): void
    {
        $user = $this->createUser('jdoe');
        
        $this->userRepository->expects($this->once())
            ->method('save')
            ->with($user);

        $this->userService->updateUser($user, $user);
    }

    public function testUserCannotUpdateOthers(): void
    {
        $targetUser = $this->createUser('target');
        $actor = $this->createUser('actor');
        
        $this->userRepository->expects($this->never())
            ->method('save');

        $this->expectException(AuthorizationException::class);
        $this->userService->updateUser($targetUser, $actor);
    }

    public function testAdminCanDeleteUser(): void
    {
        $admin = $this->createUser('admin', isAdmin: true);
        
        $this->userRepository->expects($this->once())
            ->method('delete')
            ->with('target');

        $this->userService->deleteUser('target', $admin);
    }

    public function testNonAdminCannotDeleteUser(): void
    {
        $actor = $this->createUser('actor');
        
        $this->userRepository->expects($this->never())
            ->method('delete');

        $this->expectException(AuthorizationException::class);
        $this->userService->deleteUser('target', $actor);
    }

    public function testAdminCanGetAllUsers(): void
    {
        $admin = $this->createUser('admin', isAdmin: true);
        $users = [$this->createUser('user1'), $this->createUser('user2')];
        
        $this->userRepository->expects($this->once())
            ->method('findAll')
            ->willReturn($users);

        $result = $this->userService->getAllUsers($admin);
        $this->assertSame($users, $result);
    }

    public function testNonAdminCannotGetAllUsers(): void
    {
        $actor = $this->createUser('actor');
        
        $this->userRepository->expects($this->never())
            ->method('findAll');

        $this->expectException(AuthorizationException::class);
        $this->userService->getAllUsers($actor);
    }

    public function testUserCanViewOwnPreferences(): void
    {
        $user = $this->createUser('jdoe');
        
        $this->userRepository->expects($this->once())
            ->method('getPreferences')
            ->with('jdoe');

        $this->userService->getPreferences('jdoe', $user);
    }

    public function testUserCannotViewOthersPreferences(): void
    {
        $actor = $this->createUser('actor');
        
        $this->userRepository->expects($this->never())
            ->method('getPreferences');

        $this->expectException(AuthorizationException::class);
        $this->userService->getPreferences('target', $actor);
    }

    public function testUserCanUpdateOwnPreference(): void
    {
        $user = $this->createUser('jdoe');
        $key = 'TIMEZONE';
        $value = 'America/New_York';

        $this->userRepository->expects($this->once())
            ->method('savePreference')
            ->with('jdoe', $this->callback(function ($pref) use ($key, $value) {
                return $pref instanceof \WebCalendar\Core\Domain\ValueObject\UserPreference
                    && $pref->key() === $key
                    && $pref->value() === $value;
            }));

        $this->userService->updatePreference('jdoe', $key, $value, $user);
    }

    public function testUserCannotUpdateOthersPreference(): void
    {
        $actor = $this->createUser('actor');

        $this->userRepository->expects($this->never())
            ->method('savePreference');

        $this->expectException(AuthorizationException::class);
        $this->userService->updatePreference('target', 'TIMEZONE', 'UTC', $actor);
    }

    public function testHashPassword(): void
    {
        $hash = $this->userService->hashPassword('secret123');

        $this->assertNotEmpty($hash);
        $this->assertNotSame('secret123', $hash);
        $this->assertTrue(password_verify('secret123', $hash));
        $this->assertFalse(password_verify('wrong', $hash));
    }

    public function testAdminCanViewAnyUserPreferences(): void
    {
        $admin = $this->createUser('admin', isAdmin: true);

        $this->userRepository->expects($this->once())
            ->method('getPreferences')
            ->with('target');

        $this->userService->getPreferences('target', $admin);
    }

    public function testAdminCanUpdateAnyUserPreference(): void
    {
        $admin = $this->createUser('admin', isAdmin: true);

        $this->userRepository->expects($this->once())
            ->method('savePreference')
            ->with('target', $this->callback(function ($pref) {
                return $pref instanceof \WebCalendar\Core\Domain\ValueObject\UserPreference
                    && $pref->key() === 'TIMEZONE'
                    && $pref->value() === 'UTC';
            }));

        $this->userService->updatePreference('target', 'TIMEZONE', 'UTC', $admin);
    }

    public function testAdminCanUpdateOwnRecord(): void
    {
        $admin = $this->createUser('admin', isAdmin: true);

        $this->userRepository->expects($this->once())
            ->method('save')
            ->with($admin);

        $this->userService->updateUser($admin, $admin);
    }
}
