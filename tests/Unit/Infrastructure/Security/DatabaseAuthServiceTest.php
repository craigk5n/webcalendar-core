<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Infrastructure\Security;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Infrastructure\Security\DatabaseAuthService;
use WebCalendar\Core\Domain\Entity\User;

final class DatabaseAuthServiceTest extends TestCase
{
    /** @var UserRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $userRepository;
    private DatabaseAuthService $authService;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->authService = new DatabaseAuthService($this->userRepository);
    }

    public function testAuthenticateReturnsTrueWithValidCredentials(): void
    {
        $username = 'jdoe';
        $password = 'secret123';
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $this->userRepository->expects($this->once())
            ->method('getPasswordHash')
            ->with($username)
            ->willReturn($hashedPassword);
        $this->userRepository->method('findByLogin')
            ->with($username)
            ->willReturn($this->user($username, enabled: true));

        $this->assertTrue($this->authService->authenticate($username, $password));
    }

    public function testAuthenticateRejectsDisabledAccount(): void
    {
        $this->userRepository->method('getPasswordHash')
            ->willReturn(password_hash('secret123', PASSWORD_DEFAULT));
        $this->userRepository->method('findByLogin')
            ->with('jdoe')
            ->willReturn($this->user('jdoe', enabled: false));

        $this->assertFalse($this->authService->authenticate('jdoe', 'secret123'));
    }

    public function testVerifyHashAcceptsEnabledAccount(): void
    {
        $hash = password_hash('secret123', PASSWORD_DEFAULT);
        $this->userRepository->method('getPasswordHash')->willReturn($hash);
        $this->userRepository->method('findByLogin')
            ->willReturn($this->user('jdoe', enabled: true));

        $this->assertTrue($this->authService->verifyHash('jdoe', $hash));
    }

    public function testVerifyHashRejectsDisabledAccount(): void
    {
        $hash = password_hash('secret123', PASSWORD_DEFAULT);
        $this->userRepository->method('getPasswordHash')->willReturn($hash);
        $this->userRepository->method('findByLogin')
            ->willReturn($this->user('jdoe', enabled: false));

        $this->assertFalse($this->authService->verifyHash('jdoe', $hash));
    }

    public function testAuthenticateRejectsHashWithoutUserRow(): void
    {
        $this->userRepository->method('getPasswordHash')
            ->willReturn(password_hash('secret123', PASSWORD_DEFAULT));
        $this->userRepository->method('findByLogin')->willReturn(null);

        $this->assertFalse($this->authService->authenticate('jdoe', 'secret123'));
    }

    public function testAuthenticateReturnsFalseWithInvalidPassword(): void
    {
        $username = 'jdoe';
        $password = 'wrong-password';
        $hashedPassword = password_hash('correct-password', PASSWORD_DEFAULT);

        $this->userRepository->expects($this->once())
            ->method('getPasswordHash')
            ->with($username)
            ->willReturn($hashedPassword);

        $this->assertFalse($this->authService->authenticate($username, $password));
    }

    public function testAuthenticateReturnsFalseWhenUserNotFound(): void
    {
        $username = 'missing';
        
        $this->userRepository->expects($this->once())
            ->method('getPasswordHash')
            ->with($username)
            ->willReturn(null);

        $this->assertFalse($this->authService->authenticate($username, 'any-password'));
    }

    private function user(string $login, bool $enabled): User
    {
        return new User($login, 'First', 'Last', $login . '@example.com', false, $enabled);
    }
}
