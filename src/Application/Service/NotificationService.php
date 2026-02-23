<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Application\Contract\EmailMessage;
use WebCalendar\Core\Application\Contract\EmailProviderInterface;
use WebCalendar\Core\Application\Contract\WebhookProviderInterface;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for dispatching notifications (email, webhooks).
 */
final readonly class NotificationService
{
    private LoggerInterface $logger;

    public function __construct(
        private EmailProviderInterface $emailProvider,
        private WebhookProviderInterface $webhookProvider,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Sends a reminder for an event to a user.
     */
    public function sendReminder(Event $event, User $user): void
    {
        $this->logger->info('Sending reminder', ['event_id' => $event->id()->value(), 'user' => $user->login()]);
        
        $subject = 'Reminder: ' . $event->name();
        $textBody = sprintf(
            "Hello %s,\n\nThis is a reminder for the following event:\n\nTitle: %s\nDate: %s\nLocation: %s\n\nDescription: %s",
            $user->firstName(),
            $event->name(),
            $event->start()->format('Y-m-d H:i'),
            $event->location(),
            $event->description()
        );

        $htmlBody = sprintf(
            "<p>Hello %s,</p><p>This is a reminder for the following event:</p><ul><li><strong>Title:</strong> %s</li><li><strong>Date:</strong> %s</li><li><strong>Location:</strong> %s</li></ul><p>%s</p>",
            htmlspecialchars($user->firstName(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($event->name(), ENT_QUOTES, 'UTF-8'),
            $event->start()->format('Y-m-d H:i'),
            htmlspecialchars($event->location(), ENT_QUOTES, 'UTF-8'),
            nl2br(htmlspecialchars($event->description(), ENT_QUOTES, 'UTF-8'))
        );

        $message = new EmailMessage(
            to: $user->email(),
            subject: $subject,
            htmlBody: $htmlBody,
            textBody: $textBody
        );

        try {
            $this->emailProvider->send($message);
            $this->logger->debug('Reminder email sent', ['to' => $user->email()]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send reminder email', ['to' => $user->email(), 'error' => $e->getMessage()]);
        }

        try {
            $this->webhookProvider->trigger('event.reminder', [
                'event_id' => $event->id()->value(),
                'user_login' => $user->login(),
                'event_name' => $event->name()
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to send reminder webhook', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Notifies participants about an event via webhook.
     *
     * @param Event $event The event to notify about.
     * @param User[] $participants The participants to notify.
     * @param string $webhookUrl The webhook URL to trigger.
     */
    public function notifyParticipants(Event $event, array $participants, string $webhookUrl): void
    {
        $this->logger->info('Notifying participants', ['event_id' => $event->id()->value(), 'webhook_url' => $webhookUrl]);

        $payload = [
            'event_id' => $event->id()->value(),
            'event_name' => $event->name(),
            'participants' => array_map(fn(User $u) => $u->login(), $participants),
        ];

        try {
            $this->webhookProvider->trigger($webhookUrl, $payload);
        } catch (\Exception $e) {
            $this->logger->error('Failed to notify participants via webhook', ['error' => $e->getMessage()]);
        }
    }
}
