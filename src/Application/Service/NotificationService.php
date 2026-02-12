<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Application\Contract\EmailProviderInterface;
use WebCalendar\Core\Application\Contract\WebhookProviderInterface;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;

/**
 * Service for dispatching notifications (email, webhooks).
 */
final readonly class NotificationService
{
    public function __construct(
        private EmailProviderInterface $emailProvider,
        private WebhookProviderInterface $webhookProvider
    ) {
    }

    /**
     * Sends a reminder for an event to a user.
     */
    public function sendReminder(Event $event, User $user): void
    {
        $subject = 'Reminder: ' . $event->name();
        $body = sprintf(
            "Hello %s,

This is a reminder for your event: %s
Time: %s
Location: %s

Description:
%s",
            $user->firstName(),
            $event->name(),
            $event->start()->format('Y-m-d H:i'),
            $event->location(),
            $event->description()
        );

        $this->emailProvider->send($user->email(), $subject, $body);
    }

    /**
     * Notifies participants about an event change.
     * 
     * @param User[] $participants
     */
    public function notifyParticipants(Event $event, array $participants, ?string $webhookUrl = null): void
    {
        // Send emails to participants
        foreach ($participants as $participant) {
            $subject = 'Event Notification: ' . $event->name();
            $body = 'Details for event ' . $event->name() . ' have been updated.';
            $this->emailProvider->send($participant->email(), $subject, $body);
        }

        // Trigger webhook if URL provided
        if ($webhookUrl) {
            $this->webhookProvider->trigger($webhookUrl, [
                'event_id' => $event->id()->value(),
                'event_name' => $event->name(),
                'action' => 'notify'
            ]);
        }
    }
}
