<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\SecurityService;
use WebCalendar\Core\Domain\Repository\TokenRepositoryInterface;

final class SecurityServiceTest extends TestCase
{
    /** @var TokenRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $tokenRepository;
    private SecurityService $securityService;

    protected function setUp(): void
    {
        $this->tokenRepository = $this->createMock(TokenRepositoryInterface::class);
        $this->securityService = new SecurityService(
            'secret-key-123',
            $this->tokenRepository
        );
    }

    public function testGenerateToken(): void
    {
        $this->tokenRepository->expects($this->once())
            ->method('store')
            ->with(
                $this->isType('string'),
                'session',
                'jdoe',
                86400
            );

        $this->tokenRepository->expects($this->once())
            ->method('validate')
            ->with($this->isType('string'), 'session', 'jdoe')
            ->willReturn(true);

        $token = $this->securityService->generateToken('jdoe');
        $this->assertNotEmpty($token);
        $this->assertTrue($this->securityService->validateToken($token, 'jdoe'));
    }

    public function testValidateTokenFailWithWrongUser(): void
    {
        $this->tokenRepository->expects($this->once())
            ->method('store');

        $this->tokenRepository->expects($this->once())
            ->method('validate')
            ->with($this->isType('string'), 'session', 'other')
            ->willReturn(false);

        $token = $this->securityService->generateToken('jdoe');
        $this->assertFalse($this->securityService->validateToken($token, 'other'));
    }

    public function testCsrfProtection(): void
    {
        $this->tokenRepository->expects($this->once())
            ->method('store')
            ->with(
                $this->isType('string'),
                'csrf',
                '',
                3600
            );

        $this->tokenRepository->expects($this->once())
            ->method('validate')
            ->with($this->isType('string'), 'csrf', '')
            ->willReturn(true);

        // CSRF token is consumed on successful validation
        $this->tokenRepository->expects($this->once())
            ->method('delete')
            ->with($this->isType('string'), 'csrf');

        $token = $this->securityService->generateCsrfToken();
        $this->assertNotEmpty($token);
        $this->assertTrue($this->securityService->validateCsrfToken($token));
    }

    public function testInvalidateToken(): void
    {
        $this->tokenRepository->expects($this->once())
            ->method('delete')
            ->with('test-token', 'session');

        $this->securityService->invalidateToken('test-token');
    }

    public function testInvalidateAllSessionsForUser(): void
    {
        $this->tokenRepository->expects($this->once())
            ->method('deleteByData')
            ->with('session', 'jdoe');

        $this->securityService->invalidateAllSessionsForUser('jdoe');
    }

    public function testCleanupExpiredTokens(): void
    {
        $this->tokenRepository->expects($this->once())
            ->method('deleteExpired');

        $this->securityService->cleanupExpiredTokens();
    }

    public function testSanitizeHtml(): void
    {
        $input = '<script>alert("xss")</script>Hello';
        $expected = '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;Hello';
        
        $this->assertEquals($expected, $this->securityService->sanitizeHtml($input));
    }

    public function testCsrfTokenWithSessionId(): void
    {
        $sessionId = 'session-123';

        $this->tokenRepository->expects($this->once())
            ->method('store')
            ->with(
                $this->isType('string'),
                'csrf',
                $sessionId,
                3600
            );

        $this->tokenRepository->expects($this->once())
            ->method('validate')
            ->with($this->isType('string'), 'csrf', $sessionId)
            ->willReturn(true);

        // CSRF token is consumed on successful validation
        $this->tokenRepository->expects($this->once())
            ->method('delete')
            ->with($this->isType('string'), 'csrf');

        $token = $this->securityService->generateCsrfToken($sessionId);
        $this->assertTrue($this->securityService->validateCsrfToken($token, $sessionId));
    }

    public function testCsrfTokenNotDeletedOnFailedValidation(): void
    {
        $this->tokenRepository->expects($this->once())
            ->method('validate')
            ->willReturn(false);

        // Token should NOT be deleted when validation fails
        $this->tokenRepository->expects($this->never())
            ->method('delete');

        $this->assertFalse($this->securityService->validateCsrfToken('invalid-token'));
    }
}
