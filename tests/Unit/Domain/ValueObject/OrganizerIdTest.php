<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\ValueObject\OrganizerId;

final class OrganizerIdTest extends TestCase
{
    public function testHoldsANonNegativeValue(): void
    {
        $this->assertSame(0, (new OrganizerId(0))->value());
        $this->assertSame(42, (new OrganizerId(42))->value());
    }

    public function testNegativeValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OrganizerId(-1);
    }

    public function testEquality(): void
    {
        $this->assertTrue((new OrganizerId(5))->equals(new OrganizerId(5)));
        $this->assertFalse((new OrganizerId(5))->equals(new OrganizerId(6)));
    }
}
