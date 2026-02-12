<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\NotificationService;
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
            ->with('john@example.com', $this->stringContains('Reminder'), $this->stringContains('Meeting'));

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

    private function createEvent(string $name): Event
    {
        return new Event(
            id: new EventId(1),
            uid: 'uid-1',
            name: $name,
            description: 'Description',
            location: 'Location',
            start: new \DateTimeImmutable(),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );
    }
}
