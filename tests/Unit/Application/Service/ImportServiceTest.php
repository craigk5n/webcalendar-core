<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\ImportService;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Infrastructure\ICal\EventMapper;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\EventId;

final class ImportServiceTest extends TestCase
{
    /** @var EventRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventRepository;
    private ImportService $importService;

    protected function setUp(): void
    {
        $this->eventRepository = $this->createMock(EventRepositoryInterface::class);
        $this->importService = new ImportService(
            $this->eventRepository,
            new EventMapper()
        );
    }

    public function testImportIcalCreatesNewEvents(): void
    {
        $icsContent = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//WebCalendar//NONSGML v1.0//EN
BEGIN:VEVENT
UID:uid-1
SUMMARY:Event 1
DTSTART:20260211T100000Z
DURATION:PT1H
END:VEVENT
END:VCALENDAR
ICS;

        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);

        $this->eventRepository->expects($this->once())
            ->method('findByUid')
            ->with('uid-1')
            ->willReturn(null);

        $this->eventRepository->expects($this->once())
            ->method('create')
            ->with($this->isInstanceOf(Event::class));

        $this->importService->importIcal($icsContent, $user);
    }
}
