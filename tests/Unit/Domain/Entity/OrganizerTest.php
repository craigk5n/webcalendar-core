<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Organizer;
use WebCalendar\Core\Domain\ValueObject\OrganizerId;

final class OrganizerTest extends TestCase
{
    public function testCanBeCreatedWithFullData(): void
    {
        $organizer = new Organizer(
            id: new OrganizerId(3),
            name: 'Alice Organizer',
            email: 'alice@example.com',
            phone: '+1-555-0101',
            url: 'https://alice.example.com',
        );

        $this->assertSame(3, $organizer->id()->value());
        $this->assertSame('Alice Organizer', $organizer->name());
        $this->assertSame('alice@example.com', $organizer->email());
        $this->assertSame('+1-555-0101', $organizer->phone());
        $this->assertSame('https://alice.example.com', $organizer->url());
    }

    public function testOnlyNameIsRequired(): void
    {
        $organizer = new Organizer(id: new OrganizerId(0), name: 'Bob NoContact');

        $this->assertNull($organizer->email());
        $this->assertNull($organizer->phone());
        $this->assertNull($organizer->url());
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Organizer(id: new OrganizerId(1), name: '');
    }

    public function testInvalidEmailIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Organizer(id: new OrganizerId(1), name: 'Bad Email', email: 'not-an-email');
    }

    public function testWithIdReturnsACopyCarryingTheNewIdentity(): void
    {
        $organizer = new Organizer(id: new OrganizerId(0), name: 'Fresh', email: 'f@example.com');

        $saved = $organizer->withId(new OrganizerId(7));

        $this->assertSame(7, $saved->id()->value());
        $this->assertSame('f@example.com', $saved->email());
        $this->assertSame(0, $organizer->id()->value(), 'the original is untouched');
    }
}
