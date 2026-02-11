<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\UserService;
use WebCalendar\Core\Domain\Entity\User;
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

    public function testGetUserByLogin(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        
        $this->userRepository->expects($this->once())
            ->method('findByLogin')
            ->with('jdoe')
            ->willReturn($user);

        $result = $this->userService->getUserByLogin('jdoe');
        $this->assertSame($user, $result);
    }

    public function testCreateUser(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        
        $this->userRepository->expects($this->once())
            ->method('save')
            ->with($user);

        $this->userService->createUser($user);
    }

    public function testUpdatePreference(): void
    {
        $login = 'jdoe';
        $key = 'TIMEZONE';
        $value = 'America/New_York';

        $this->userRepository->expects($this->once())
            ->method('savePreference')
            ->with($login, $this->callback(function ($pref) use ($key, $value) {
                return $pref instanceof \WebCalendar\Core\Domain\ValueObject\UserPreference
                    && $pref->key() === $key
                    && $pref->value() === $value;
            }));

        $this->userService->updatePreference($login, $key, $value);
    }
}
