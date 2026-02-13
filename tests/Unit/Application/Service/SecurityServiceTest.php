<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\SecurityService;

final class SecurityServiceTest extends TestCase
{
    private SecurityService $securityService;

    protected function setUp(): void
    {
        $this->securityService = new SecurityService('secret-key-123');
    }

    public function testGenerateToken(): void
    {
        $token = $this->securityService->generateToken('jdoe');
        $this->assertNotEmpty($token);
        $this->assertTrue($this->securityService->validateToken($token, 'jdoe'));
    }

    public function testValidateTokenFailWithWrongUser(): void
    {
        $token = $this->securityService->generateToken('jdoe');
        $this->assertFalse($this->securityService->validateToken($token, 'other'));
    }

    public function testCsrfProtection(): void
    {
        $token = $this->securityService->generateCsrfToken();
        $this->assertNotEmpty($token);
        $this->assertTrue($this->securityService->validateCsrfToken($token));
    }
}
