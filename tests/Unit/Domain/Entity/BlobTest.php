<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Blob;
use WebCalendar\Core\Domain\ValueObject\BlobType;

final class BlobTest extends TestCase
{
    public function testCanBeCreatedWithValidData(): void
    {
        $date = new \DateTimeImmutable('2026-02-11 10:00:00');
        $blob = new Blob(
            id: 1,
            eventId: 123,
            login: 'jdoe',
            name: 'document.pdf',
            description: 'Meeting notes',
            size: 1024,
            mimeType: 'application/pdf',
            type: BlobType::ATTACHMENT,
            date: $date,
            content: 'binary data'
        );

        $this->assertSame(1, $blob->id());
        $this->assertSame(123, $blob->eventId());
        $this->assertSame('jdoe', $blob->login());
        $this->assertSame('document.pdf', $blob->name());
        $this->assertSame('Meeting notes', $blob->description());
        $this->assertSame(1024, $blob->size());
        $this->assertSame('application/pdf', $blob->mimeType());
        $this->assertSame(BlobType::ATTACHMENT, $blob->type());
        $this->assertEquals($date, $blob->date());
        $this->assertSame('binary data', $blob->content());
    }
}
