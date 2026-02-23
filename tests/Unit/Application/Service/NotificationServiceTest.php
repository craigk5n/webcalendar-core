<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\NotificationService;
use WebCalendar\Core\Application\Contract\EmailException;
use WebCalendar\Core\Application\Contract\EmailMessage;
use WebCalendar\Core\Application\Contract\EmailProviderInterface;
use WebCalendar\Core\Application\Contract\WebhookProviderInterface;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;

final class NotificationServiceTest extends TestCase
{
  /** @var EmailProviderInterface&\PHPUnit\Framework\MockObject\MockObject */
  private $emailProvider;
  /** @var WebhookProviderInterface&\PHPUnit\Framework\MockObject\MockObject */
  private $webhookProvider;
  private NotificationService $notificationService;

  protected function setUp(): void
  {
    $this->emailProvider = $this->createMock(EmailProviderInterface::class);
    $this->webhookProvider = $this->createMock(WebhookProviderInterface::class);
    $this->notificationService = new NotificationService($this->emailProvider, $this->webhookProvider);
  }

  public function testSendReminderSendsEmail(): void
  {
    $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
    $event = $this->createEvent('Meeting');

    $this->emailProvider->expects($this->once())
      ->method('send')
      ->with($this->callback(function (EmailMessage $message) {
        return $message->to === 'john@example.com'
          && str_contains($message->subject, 'Reminder')
          && str_contains($message->textBody, 'Meeting');
      }));

    $this->notificationService->sendReminder($event, $user);
  }

  public function testSendReminderIncludesHtmlBody(): void
  {
    $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
    $event = $this->createEvent('Team <Standup>');

    $this->emailProvider->expects($this->once())
      ->method('send')
      ->with($this->callback(function (EmailMessage $message) {
        // htmlBody should exist and contain escaped content
        return $message->htmlBody !== ''
          && str_contains($message->htmlBody, 'Team &lt;Standup&gt;')
          && str_contains($message->htmlBody, 'John')
          && str_contains($message->htmlBody, '<strong>Title:</strong>');
      }));

    $this->notificationService->sendReminder($event, $user);
  }

  public function testSendReminderContinuesWhenEmailFails(): void
  {
    $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
    $event = $this->createEvent('Meeting');

    $this->emailProvider->expects($this->once())
      ->method('send')
      ->willThrowException(EmailException::sendFailed('john@example.com', 'SMTP error'));

    // Webhook should still be triggered even though email failed
    $this->webhookProvider->expects($this->once())
      ->method('trigger');

    // Should not throw
    $this->notificationService->sendReminder($event, $user);
  }

  public function testSendReminderContinuesWhenWebhookFails(): void
  {
    $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
    $event = $this->createEvent('Meeting');

    $this->emailProvider->expects($this->once())
      ->method('send');

    $this->webhookProvider->expects($this->once())
      ->method('trigger')
      ->willThrowException(new \RuntimeException('Connection refused'));

    // Should not throw
    $this->notificationService->sendReminder($event, $user);
  }

  public function testNotifyParticipantsTriggersWebhook(): void
  {
    $event = $this->createEvent('New Event');
    $webhookUrl = 'https://example.com/webhook';

    $this->webhookProvider->expects($this->once())
      ->method('trigger')
      ->with($webhookUrl, $this->arrayHasKey('event_name'));

    $this->notificationService->notifyParticipants($event, [], $webhookUrl);
  }

  public function testNotifyParticipantsContinuesWhenWebhookFails(): void
  {
    $event = $this->createEvent('New Event');
    $webhookUrl = 'https://example.com/webhook';

    $this->webhookProvider->expects($this->once())
      ->method('trigger')
      ->willThrowException(new \RuntimeException('Timeout'));

    // Should not throw
    $this->notificationService->notifyParticipants($event, [], $webhookUrl);
  }

  private function createEvent(string $name): Event
  {
    return new Event(
      id: new EventId(1),
      uid: 'uid-1',
      name: $name,
      description: 'Description',
      location: 'Location',
      start: new \DateTimeImmutable('2026-03-01 10:00:00'),
      duration: 60,
      createdBy: 'admin',
      type: EventType::EVENT,
      access: AccessLevel::PUBLIC
    );
  }
}
