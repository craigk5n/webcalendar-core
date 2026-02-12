<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Group;

final class GroupTest extends TestCase
{
    public function testCanBeCreatedWithValidData(): void
    {
        $group = new Group(
            id: 1,
            owner: 'admin',
            name: 'Development Team',
            lastUpdate: new \DateTimeImmutable('2026-02-11 10:00:00')
        );

        $this->assertSame(1, $group->id());
        $this->assertSame('admin', $group->owner());
        $this->assertSame('Development Team', $group->name());
        $this->assertEquals(new \DateTimeImmutable('2026-02-11 10:00:00'), $group->lastUpdate());
    }

    public function testThrowsExceptionForEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Group(
            id: 1,
            owner: 'admin',
            name: '',
            lastUpdate: new \DateTimeImmutable()
        );
    }
}
