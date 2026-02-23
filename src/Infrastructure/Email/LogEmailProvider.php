<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Email;

use WebCalendar\Core\Application\Contract\EmailMessage;
use WebCalendar\Core\Application\Contract\EmailProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Email provider that logs emails instead of sending them.
 * Useful for development and testing environments.
 */
final readonly class LogEmailProvider implements EmailProviderInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function send(EmailMessage $message): void
    {
        $this->logger->info('Email would be sent', [
            'to' => $message->to,
            'subject' => $message->subject,
            'cc' => $message->cc,
            'bcc' => $message->bcc,
            'has_html' => $message->htmlBody !== '',
            'has_text' => $message->textBody !== '',
            'attachments' => count($message->attachments),
        ]);
    }

    public function sendSimple(string $to, string $subject, string $body): void
    {
        $this->send(EmailMessage::html($to, $subject, $body));
    }
}
