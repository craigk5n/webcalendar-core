<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Resource;

final class ResourceTest extends TestCase
{
    public function testCanBeCreatedWithValidData(): void
    {
        $resource = new Resource(
            login: 'room101',
            name: 'Conference Room 101',
            admin: 'admin',
            isPublic: true,
            url: 'https://example.com/room101.ics'
        );

        $this->assertSame('room101', $resource->login());
        $this->assertSame('Conference Room 101', $resource->name());
        $this->assertSame('admin', $resource->admin());
        $this->assertTrue($resource->isPublic());
        $this->assertSame('https://example.com/room101.ics', $resource->url());
    }
}
