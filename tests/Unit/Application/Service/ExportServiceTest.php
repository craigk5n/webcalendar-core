<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\ExportService;
use WebCalendar\Core\Infrastructure\ICal\EventMapper;
use WebCalendar\Core\Domain\ValueObject\EventCollection;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;

final class ExportServiceTest extends TestCase
{
    private ExportService $exportService;

    protected function setUp(): void
    {
        $this->exportService = new ExportService(new EventMapper());
    }

    public function testExportIcal(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $event = new Event(
            id: new EventId(1),
            uid: 'test-uid',
            name: 'Test Event',
            description: 'This is a test',
            location: 'Online',
            start: $start,
            duration: 60,
            createdBy: 'creator-login',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $events = new EventCollection([$event]);
        $ics = $this->exportService->exportIcal($events);

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('SUMMARY:Test Event', $ics);
        $this->assertStringContainsString('UID:test-uid', $ics);
        $this->assertStringContainsString('END:VEVENT', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
    }
}
