<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Repository;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;

final class UserRepositoryInterfaceTest extends TestCase
{
    public function testCanMockInterface(): void
    {
        $user = new User(
            login: 'jdoe',
            firstName: 'John',
            lastName: 'Doe',
            email: 'john@example.com',
            isAdmin: false,
            isEnabled: true
        );

        $repository = $this->createMock(UserRepositoryInterface::class);
        
        $repository->expects($this->once())
            ->method('findByLogin')
            ->with('jdoe')
            ->willReturn($user);

        $this->assertSame($user, $repository->findByLogin('jdoe'));
    }
}
