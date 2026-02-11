<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\ValueObject\EventId;

final class EventIdTest extends TestCase
{
    public function testCanBeCreatedFromValidInteger(): void
    {
        $id = new EventId(123);
        $this->assertSame(123, $id->value());
    }

    public function testThrowsExceptionForInvalidInteger(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EventId(0);
    }

    public function testThrowsExceptionForNegativeInteger(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EventId(-5);
    }

    public function testEquality(): void
    {
        $id1 = new EventId(10);
        $id2 = new EventId(10);
        $id3 = new EventId(20);

        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));
    }
}
