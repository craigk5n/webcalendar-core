<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Contract;

/**
 * Represents an email message to be sent.
 */
final class EmailMessage
{
    /**
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $htmlBody HTML body content
     * @param string $textBody Plain text body content (optional)
     * @param array<string> $cc CC recipients
     * @param array<string> $bcc BCC recipients
     * @param string|null $replyTo Reply-to address
     * @param array<array{path: string, name: string}> $attachments File attachments
     */
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $htmlBody,
        public readonly string $textBody = '',
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly ?string $replyTo = null,
        public readonly array $attachments = [],
    ) {
    }

    /**
     * Creates a simple text email.
     */
    public static function text(string $to, string $subject, string $body): self
    {
        return new self(
            to: $to,
            subject: $subject,
            htmlBody: '',
            textBody: $body
        );
    }

    /**
     * Creates a simple HTML email.
     */
    public static function html(string $to, string $subject, string $body): self
    {
        return new self(
            to: $to,
            subject: $subject,
            htmlBody: $body
        );
    }
}
