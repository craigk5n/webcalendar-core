<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\FeedService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\EventCollection;
use WebCalendar\Core\Domain\ValueObject\DateRange;

final class FeedServiceTest extends TestCase
{
    /** @var EventRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventRepository;
    private FeedService $feedService;

    protected function setUp(): void
    {
        $this->eventRepository = $this->createMock(EventRepositoryInterface::class);
        $eventService = new EventService($this->eventRepository);
        $this->feedService = new FeedService($eventService, 'https://example.com/calendar');
    }

    public function testGenerateFreeBusy(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        $range = new DateRange(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-02-28'));

        $this->eventRepository->expects($this->once())
            ->method('findByDateRange')
            ->willReturn([]);

        $result = $this->feedService->generateFreeBusy($user, $range);
        
        $this->assertStringContainsString('BEGIN:VCALENDAR', $result);
        $this->assertStringContainsString('BEGIN:VFREEBUSY', $result);
        $this->assertStringContainsString('END:VFREEBUSY', $result);
        $this->assertStringContainsString('END:VCALENDAR', $result);
    }

    public function testRssFeedEscapesUserContent(): void
    {
        $user = new User('jdoe', 'John<script>alert(1)</script>', 'Doe', 'john@example.com', false, true);
        $range = new DateRange(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-02-28'));

        $this->eventRepository->expects($this->once())
            ->method('findByDateRange')
            ->willReturn([]);

        $result = $this->feedService->generateRss($user, $range);
        
        // Content should be in CDATA sections, not raw script tags
        $this->assertStringContainsString('<![CDATA[Upcoming Events for John<script>alert(1)</script> Doe]]>', $result);
        // Verify the script tag is inside CDATA, not as raw XML
        $this->assertMatchesRegularExpression('/<title><!\[CDATA\[.*<script>.*<\/script>.*\]\]><\/title>/s', $result);
    }

    public function testRssFeedUsesConfigurableBaseUrl(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        $range = new DateRange(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-02-28'));

        $this->eventRepository->expects($this->once())
            ->method('findByDateRange')
            ->willReturn([]);

        $result = $this->feedService->generateRss($user, $range);
        
        $this->assertStringContainsString('https://example.com/calendar', $result);
    }
}
