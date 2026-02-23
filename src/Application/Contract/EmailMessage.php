<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Contract;

/**
 * Represents an email message to be sent.
 */
final readonly class EmailMessage
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
        public string $to,
        public string $subject,
        public string $htmlBody,
        public string $textBody = '',
        public array $cc = [],
        public array $bcc = [],
        public ?string $replyTo = null,
        public array $attachments = [],
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
