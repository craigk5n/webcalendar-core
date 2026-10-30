<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Infrastructure\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Contract\RateLimiterInterface;
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

    /** @return array<string, array{string}> */
    public static function collationVariants(): array
    {
        return [
            'accent' => ['àlice'],
            'trailing space' => ['alice '],
            'dotted capital I' => ['ALİCE'],
        ];
    }

    /**
     * MySQL's default collations match these to alice's row, but each would
     * get a rate-limit bucket of its own.
     */
    #[DataProvider('collationVariants')]
    public function testLoginThatMatchesOnlyByCollationIsRefused(string $variant): void
    {
        $hash = password_hash('secret123', PASSWORD_DEFAULT, ['cost' => 4]);
        $this->userRepository->method('getPasswordHash')->willReturn($hash);
        $this->userRepository->method('findByLogin')->willReturn($this->user('alice', enabled: true));

        $this->assertFalse($this->authService->authenticate($variant, 'secret123'));
        $this->assertFalse($this->authService->verifyHash($variant, $hash));
    }

    public function testAsciiCaseDifferenceStillLogsIn(): void
    {
        $this->userRepository->method('getPasswordHash')
            ->willReturn(password_hash('secret123', PASSWORD_DEFAULT, ['cost' => 4]));
        $this->userRepository->method('findByLogin')->willReturn($this->user('alice', enabled: true));

        $this->assertTrue($this->authService->authenticate('Alice', 'secret123'));
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

    // ---- rate limiting ----------------------------------------------------

    public function testConcurrentLoginsCannotExceedTheLimit(): void
    {
        // Each request starts the next one while it is still between the
        // rate-limit check and its own bookkeeping, as overlapping PHP
        // workers would. Only maxLoginAttempts of them may reach the
        // password check.
        $auth = new DatabaseAuthService($this->userRepository, new InMemoryRateLimiter(), 5, 900);
        $checks = 0;
        $this->userRepository->method('getPasswordHash')->willReturnCallback(
            function () use ($auth, &$checks): string {
                $checks++;
                if ($checks < 20) {
                    $auth->authenticate('alice', 'wrong-' . $checks);
                }
                return password_hash('right', PASSWORD_DEFAULT, ['cost' => 4]);
            }
        );
        $this->userRepository->method('findByLogin')->willReturn($this->user('alice', enabled: true));

        $auth->authenticate('alice', 'wrong-0');

        $this->assertSame(5, $checks);
    }

    public function testLockedAccountRejectsTheRightPassword(): void
    {
        $auth = $this->limitedAuth('right');
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($auth->authenticate('alice', 'wrong'));
        }

        $this->assertFalse($auth->authenticate('alice', 'right'));
        $this->assertSame(0, $auth->getRemainingAttempts('alice'));
    }

    public function testAttemptsWhileLockedDoNotExtendTheLockout(): void
    {
        $limiter = new InMemoryRateLimiter();
        $this->userRepository->method('getPasswordHash')
            ->willReturn(password_hash('right', PASSWORD_DEFAULT, ['cost' => 4]));
        $this->userRepository->method('findByLogin')->willReturn($this->user('alice', enabled: true));
        $auth = new DatabaseAuthService($this->userRepository, $limiter, 5, 900);

        for ($i = 0; $i < 12; $i++) {
            $auth->authenticate('alice', 'wrong');
        }

        $this->assertSame(5, $limiter->count('login:alice', 'login'));
    }

    public function testSuccessfulLoginClearsTheLoginBucket(): void
    {
        $auth = $this->limitedAuth('right');
        $auth->authenticate('alice', 'wrong');
        $auth->authenticate('alice', 'wrong');

        $this->assertTrue($auth->authenticate('alice', 'right'));
        $this->assertSame(5, $auth->getRemainingAttempts('alice'));
    }

    public function testVerifyHashIsLimitedInItsOwnBucket(): void
    {
        $hash = password_hash('right', PASSWORD_DEFAULT, ['cost' => 4]);
        $auth = $this->limitedAuth('right', $hash);
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($auth->verifyHash('alice', 'not-the-hash'));
        }

        $this->assertFalse($auth->verifyHash('alice', $hash), 'verifyHash must lock out too');
        $this->assertSame(5, $auth->getRemainingAttempts('alice'), 'wrong hashes must not use up logins');
        $this->assertTrue($auth->authenticate('alice', 'right'));
    }

    public function testVerifyHashSuccessDoesNotResetTheLoginLockout(): void
    {
        $hash = password_hash('right', PASSWORD_DEFAULT, ['cost' => 4]);
        $auth = $this->limitedAuth('right', $hash);
        for ($i = 0; $i < 5; $i++) {
            $auth->authenticate('alice', 'wrong');
        }

        $this->assertTrue($auth->verifyHash('alice', $hash));
        $this->assertSame(0, $auth->getRemainingAttempts('alice'));
        $this->assertFalse($auth->authenticate('alice', 'right'));
    }

    private function limitedAuth(string $password, ?string $hash = null): DatabaseAuthService
    {
        $hash ??= password_hash($password, PASSWORD_DEFAULT, ['cost' => 4]);
        $this->userRepository->method('getPasswordHash')->willReturn($hash);
        $this->userRepository->method('findByLogin')->willReturn($this->user('alice', enabled: true));

        return new DatabaseAuthService($this->userRepository, new InMemoryRateLimiter(), 5, 900);
    }

    private function user(string $login, bool $enabled): User
    {
        return new User($login, 'First', 'Last', $login . '@example.com', false, $enabled);
    }
}

/** Counts attempts per (identifier, action), with no expiry. */
final class InMemoryRateLimiter implements RateLimiterInterface
{
    /** @var array<string, int> */
    private array $attempts = [];

    public function isAllowed(string $identifier, string $action, int $maxAttempts, int $windowSeconds): bool
    {
        return ($this->attempts[$identifier . '|' . $action] ?? 0) < $maxAttempts;
    }

    public function recordAttempt(string $identifier, string $action, int $windowSeconds): void
    {
        $key = $identifier . '|' . $action;
        $this->attempts[$key] = ($this->attempts[$key] ?? 0) + 1;
    }

    public function getRemainingAttempts(string $identifier, string $action, int $maxAttempts, int $windowSeconds): int
    {
        return max(0, $maxAttempts - ($this->attempts[$identifier . '|' . $action] ?? 0));
    }

    public function getResetTime(string $identifier, string $action, int $windowSeconds = 3600): int
    {
        return 0;
    }

    public function count(string $identifier, string $action): int
    {
        return $this->attempts[$identifier . '|' . $action] ?? 0;
    }

    public function clear(string $identifier, string $action): void
    {
        unset($this->attempts[$identifier . '|' . $action]);
    }
}
