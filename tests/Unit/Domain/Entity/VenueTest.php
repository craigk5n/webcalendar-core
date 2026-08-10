<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Venue;
use WebCalendar\Core\Domain\ValueObject\VenueId;

final class VenueTest extends TestCase
{
    public function testCanBeCreatedWithFullData(): void
    {
        $venue = new Venue(
            id: new VenueId(5),
            name: 'Community Hall',
            address: '1 Main St',
            city: 'Harrisonburg',
            state: 'VA',
            zip: '22801',
            country: 'USA',
            latitude: 38.4496,
            longitude: -78.8689,
            url: 'https://hall.example.com',
            phone: '+1-555-0100',
        );

        $this->assertSame(5, $venue->id()->value());
        $this->assertSame('Community Hall', $venue->name());
        $this->assertSame('1 Main St', $venue->address());
        $this->assertSame('Harrisonburg', $venue->city());
        $this->assertSame('VA', $venue->state());
        $this->assertSame('22801', $venue->zip());
        $this->assertSame('USA', $venue->country());
        $this->assertSame(38.4496, $venue->latitude());
        $this->assertSame(-78.8689, $venue->longitude());
        $this->assertSame('https://hall.example.com', $venue->url());
        $this->assertSame('+1-555-0100', $venue->phone());
    }

    public function testOnlyNameIsRequired(): void
    {
        $venue = new Venue(id: new VenueId(0), name: 'Back Room');

        $this->assertSame(0, $venue->id()->value());
        $this->assertNull($venue->address());
        $this->assertNull($venue->city());
        $this->assertNull($venue->state());
        $this->assertNull($venue->zip());
        $this->assertNull($venue->country());
        $this->assertNull($venue->latitude());
        $this->assertNull($venue->longitude());
        $this->assertNull($venue->url());
        $this->assertNull($venue->phone());
        $this->assertFalse($venue->hasCoordinates());
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Venue(id: new VenueId(1), name: '  ');
    }

    public function testCoordinatesMustComeAsAPair(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Venue(id: new VenueId(1), name: 'Lonely Lat', latitude: 38.0);
    }

    public function testLatitudeRangeIsValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Venue(id: new VenueId(1), name: 'Bad', latitude: 91.0, longitude: 0.0);
    }

    public function testLongitudeRangeIsValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Venue(id: new VenueId(1), name: 'Bad', latitude: 0.0, longitude: -180.5);
    }

    public function testHasCoordinatesWhenBothPresent(): void
    {
        $venue = new Venue(
            id: new VenueId(1),
            name: 'Mapped',
            latitude: 0.0,
            longitude: 0.0,
        );

        $this->assertTrue($venue->hasCoordinates());
    }

    public function testWithIdReturnsACopyCarryingTheNewIdentity(): void
    {
        $venue = new Venue(id: new VenueId(0), name: 'Fresh', city: 'Dayton');

        $saved = $venue->withId(new VenueId(9));

        $this->assertSame(9, $saved->id()->value());
        $this->assertSame('Fresh', $saved->name());
        $this->assertSame('Dayton', $saved->city());
        $this->assertSame(0, $venue->id()->value(), 'the original is untouched');
    }
}
