<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\ValueObject\VenueId;

final class VenueIdTest extends TestCase
{
    public function testHoldsANonNegativeValue(): void
    {
        $this->assertSame(0, (new VenueId(0))->value());
        $this->assertSame(42, (new VenueId(42))->value());
    }

    public function testNegativeValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VenueId(-1);
    }

    public function testEquality(): void
    {
        $this->assertTrue((new VenueId(5))->equals(new VenueId(5)));
        $this->assertFalse((new VenueId(5))->equals(new VenueId(6)));
    }
}
