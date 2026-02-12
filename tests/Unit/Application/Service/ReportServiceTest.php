<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\ReportService;
use WebCalendar\Core\Domain\Repository\ReportRepositoryInterface;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Entity\Report;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;

final class ReportServiceTest extends TestCase
{
    /** @var ReportRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $reportRepository;
    /** @var EventRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventRepository;
    private ReportService $reportService;

    protected function setUp(): void
    {
        $this->reportRepository = $this->createMock(ReportRepositoryInterface::class);
        $this->eventRepository = $this->createMock(EventRepositoryInterface::class);
        $eventService = new EventService($this->eventRepository);
        $this->reportService = new ReportService($this->reportRepository, $eventService);
    }

    public function testGenerateReport(): void
    {
        $report = new Report(
            id: 1,
            owner: 'admin',
            name: 'Test Report',
            type: 'daily',
            templates: [
                'E' => 'Event: ${name}'
            ]
        );

        $event = new Event(
            id: new EventId(123),
            uid: 'uid-1',
            name: 'Meeting',
            description: 'Discuss things',
            location: 'Office',
            start: new \DateTimeImmutable('2026-02-11 10:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $result = $this->reportService->generateEventReport($report, $event);
        
        $this->assertSame('Event: Meeting', $result);
    }
}
