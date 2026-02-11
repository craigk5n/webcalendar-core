<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\User;

final class UserTest extends TestCase
{
    public function testCanBeCreatedWithValidData(): void
    {
        $user = new User(
            login: 'jdoe',
            firstName: 'John',
            lastName: 'Doe',
            email: 'john@example.com',
            isAdmin: true,
            isEnabled: true
        );

        $this->assertSame('jdoe', $user->login());
        $this->assertSame('John', $user->firstName());
        $this->assertSame('Doe', $user->lastName());
        $this->assertSame('john@example.com', $user->email());
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->isEnabled());
        $this->assertSame('John Doe', $user->fullName());
    }

    public function testThrowsExceptionForEmptyLogin(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new User(
            login: '',
            firstName: 'John',
            lastName: 'Doe',
            email: 'john@example.com',
            isAdmin: false,
            isEnabled: true
        );
    }

    public function testThrowsExceptionForInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new User(
            login: 'jdoe',
            firstName: 'John',
            lastName: 'Doe',
            email: 'invalid-email',
            isAdmin: false,
            isEnabled: true
        );
    }
}
