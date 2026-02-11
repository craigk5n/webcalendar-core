<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\PermissionService;
use WebCalendar\Core\Domain\Repository\PermissionRepositoryInterface;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\Permission;

final class PermissionServiceTest extends TestCase
{
    /** @var PermissionRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $permissionRepository;
    private PermissionService $permissionService;

    protected function setUp(): void
    {
        $this->permissionRepository = $this->createMock(PermissionRepositoryInterface::class);
        $this->permissionService = new PermissionService($this->permissionRepository);
    }

    public function testAdminHasAllPermissions(): void
    {
        $admin = new User('admin', 'Admin', 'User', 'admin@example.com', true, true);
        
        $this->assertTrue($this->permissionService->canAccess($admin, Permission::SYSTEM_SETTINGS));
    }

    public function testUserHasPermissionWhenBitIsSet(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        
        // Let's say bit 0 (EVENT_VIEW) is 'Y'
        $permissions = 'Y' . str_repeat('N', 63);
        
        $this->permissionRepository->expects($this->once())
            ->method('getPermissions')
            ->with('jdoe')
            ->willReturn($permissions);

        $this->assertTrue($this->permissionService->canAccess($user, Permission::EVENT_VIEW));
    }

    public function testUserDeniedPermissionWhenBitIsClear(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        
        $permissions = str_repeat('N', 64);
        
        $this->permissionRepository->expects($this->once())
            ->method('getPermissions')
            ->with('jdoe')
            ->willReturn($permissions);

        $this->assertFalse($this->permissionService->canAccess($user, Permission::SYSTEM_SETTINGS));
    }
}
