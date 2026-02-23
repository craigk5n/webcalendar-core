<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\FeedService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventCollection;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

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

  private function createUser(string $login = 'jdoe'): User
  {
    return new User($login, 'John', 'Doe', 'john@example.com', false, true);
  }

  private function createRange(): DateRange
  {
    return new DateRange(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-02-28'));
  }

  private function createEvent(int $id, string $name, string $description = '', string $uid = ''): Event
  {
    return new Event(
      id: new EventId($id),
      uid: $uid !== '' ? $uid : "uid-$id",
      name: $name,
      description: $description,
      location: 'Office',
      start: new \DateTimeImmutable('2026-02-15 10:00:00'),
      duration: 60,
      createdBy: 'jdoe',
      type: EventType::EVENT,
      access: AccessLevel::PUBLIC
    );
  }

  public function testGenerateFreeBusy(): void
  {
    $user = $this->createUser();
    $range = $this->createRange();

    $this->eventRepository->expects($this->once())
      ->method('findByDateRange')
      ->willReturn([]);

    $result = $this->feedService->generateFreeBusy($user, $range);

    $this->assertStringContainsString('BEGIN:VCALENDAR', $result);
    $this->assertStringContainsString('BEGIN:VFREEBUSY', $result);
    $this->assertStringContainsString('END:VFREEBUSY', $result);
    $this->assertStringContainsString('END:VCALENDAR', $result);
  }

  public function testGenerateFreeBusyWithEvents(): void
  {
    $user = $this->createUser();
    $range = $this->createRange();
    $event = $this->createEvent(1, 'Team Meeting');

    $this->eventRepository->expects($this->once())
      ->method('findByDateRange')
      ->willReturn([$event]);

    $result = $this->feedService->generateFreeBusy($user, $range);

    $this->assertStringContainsString('BEGIN:VCALENDAR', $result);
    $this->assertStringContainsString('FREEBUSY', $result);
  }

  public function testRssFeedWithEvents(): void
  {
    $user = $this->createUser();
    $range = $this->createRange();
    $event = $this->createEvent(1, 'Sprint Planning', 'Weekly sprint planning session');

    $this->eventRepository->expects($this->once())
      ->method('findByDateRange')
      ->willReturn([$event]);

    $result = $this->feedService->generateRss($user, $range);

    // Verify RSS structure
    $this->assertStringContainsString('<rss', $result);
    $this->assertStringContainsString('<channel>', $result);
    $this->assertStringContainsString('<item>', $result);

    // Verify event content is in CDATA sections
    $this->assertStringContainsString('<![CDATA[Sprint Planning]]>', $result);
    $this->assertStringContainsString('<![CDATA[Weekly sprint planning session]]>', $result);

    // Verify guid is present
    $this->assertStringContainsString('<![CDATA[uid-1]]>', $result);

    // Verify pubDate is present
    $this->assertStringContainsString('<pubDate>', $result);
  }

  public function testRssFeedWithMultipleEvents(): void
  {
    $user = $this->createUser();
    $range = $this->createRange();
    $event1 = $this->createEvent(1, 'Event One');
    $event2 = $this->createEvent(2, 'Event Two');

    $this->eventRepository->expects($this->once())
      ->method('findByDateRange')
      ->willReturn([$event1, $event2]);

    $result = $this->feedService->generateRss($user, $range);

    $this->assertStringContainsString('<![CDATA[Event One]]>', $result);
    $this->assertStringContainsString('<![CDATA[Event Two]]>', $result);
    $this->assertSame(2, substr_count($result, '<item>'));
  }

  public function testRssFeedEscapesUserContent(): void
  {
    $user = new User('jdoe', 'John<script>alert(1)</script>', 'Doe', 'john@example.com', false, true);
    $range = $this->createRange();

    $this->eventRepository->expects($this->once())
      ->method('findByDateRange')
      ->willReturn([]);

    $result = $this->feedService->generateRss($user, $range);

    // Content should be in CDATA sections, not raw script tags
    $this->assertStringContainsString('<![CDATA[Upcoming Events for John<script>alert(1)</script> Doe]]>', $result);
    // Verify the script tag is inside CDATA, not as raw XML
    $this->assertMatchesRegularExpression('/<title><!\[CDATA\[.*<script>.*<\/script>.*\]\]><\/title>/s', $result);
  }

  public function testRssFeedEscapesEventContent(): void
  {
    $user = $this->createUser();
    $range = $this->createRange();
    $event = $this->createEvent(1, 'Event <b>Bold</b>', 'Desc with "quotes" & <tags>');

    $this->eventRepository->expects($this->once())
      ->method('findByDateRange')
      ->willReturn([$event]);

    $result = $this->feedService->generateRss($user, $range);

    // Special characters in event name/description should be safe in CDATA
    $this->assertStringContainsString('<![CDATA[Event <b>Bold</b>]]>', $result);
    $this->assertStringContainsString('<![CDATA[Desc with "quotes" & <tags>]]>', $result);
  }

  public function testRssFeedUsesConfigurableBaseUrl(): void
  {
    $user = $this->createUser();
    $range = $this->createRange();

    $this->eventRepository->expects($this->once())
      ->method('findByDateRange')
      ->willReturn([]);

    $result = $this->feedService->generateRss($user, $range);

    $this->assertStringContainsString('https://example.com/calendar', $result);
  }
}
