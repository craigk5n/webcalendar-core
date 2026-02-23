<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Email;

use WebCalendar\Core\Application\Contract\EmailException;
use WebCalendar\Core\Application\Contract\EmailMessage;
use WebCalendar\Core\Application\Contract\EmailProviderInterface;

/**
 * SMTP-based email provider implementation.
 * 
 * Uses PHP's native mail() function or can be extended for SMTP libraries.
 */
final readonly class SmtpEmailProvider implements EmailProviderInterface
{
    public function __construct(
        private string $fromAddress,
        private string $fromName = '',
        private ?string $replyTo = null,
    ) {
    }

    public function send(EmailMessage $message): void
    {
        $this->validateRecipient($message->to);

        $boundary = $this->generateBoundary();
        $headers = $this->buildHeaders($message, $boundary);
        $body = $this->buildBody($message, $boundary);
        $subject = $this->encodeSubject($message->subject);

        $success = mail(
            $message->to,
            $subject,
            $body,
            $headers
        );

        if (!$success) {
            throw EmailException::sendFailed($message->to, error_get_last()['message'] ?? 'Unknown error');
        }
    }

    public function sendSimple(string $to, string $subject, string $body): void
    {
        $this->send(EmailMessage::text($to, $subject, $body));
    }

    private function validateRecipient(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw EmailException::invalidRecipient($email);
        }
    }

    private function buildHeaders(EmailMessage $message, string $boundary): string
    {
        $headers = [];

        // From header
        if ($this->fromName !== '') {
            $headers[] = sprintf('From: %s <%s>', $this->encodeHeader($this->fromName), $this->fromAddress);
        } else {
            $headers[] = 'From: ' . $this->fromAddress;
        }

        // Reply-To
        $replyTo = $message->replyTo ?? $this->replyTo;
        if ($replyTo !== null) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        // MIME version
        $headers[] = 'MIME-Version: 1.0';

        // Content type based on body content
        if ($message->htmlBody !== '' && $message->textBody !== '') {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        } elseif ($message->htmlBody !== '') {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        }

        // CC (validate each address to prevent header injection)
        if (!empty($message->cc)) {
            $validCc = array_filter($message->cc, fn(string $addr) => filter_var($addr, FILTER_VALIDATE_EMAIL) !== false);
            if (!empty($validCc)) {
                $headers[] = 'Cc: ' . implode(', ', $validCc);
            }
        }

        // BCC (validate each address to prevent header injection)
        if (!empty($message->bcc)) {
            $validBcc = array_filter($message->bcc, fn(string $addr) => filter_var($addr, FILTER_VALIDATE_EMAIL) !== false);
            if (!empty($validBcc)) {
                $headers[] = 'Bcc: ' . implode(', ', $validBcc);
            }
        }

        return implode("\r\n", $headers);
    }

    private function generateBoundary(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function buildBody(EmailMessage $message, string $boundary): string
    {
        // Multipart if both HTML and text
        if ($message->htmlBody !== '' && $message->textBody !== '') {
            return <<<EOT
--{$boundary}
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: base64

{$this->base64Encode($message->textBody)}

--{$boundary}
Content-Type: text/html; charset=UTF-8
Content-Transfer-Encoding: base64

{$this->base64Encode($message->htmlBody)}

--{$boundary}--
EOT;
        }

        if ($message->htmlBody !== '') {
            return $message->htmlBody;
        }

        return $message->textBody;
    }

    private function encodeSubject(string $subject): string
    {
        if ($this->isAscii($subject)) {
            return $subject;
        }

        return sprintf('=?UTF-8?B?%s?=', base64_encode($subject));
    }

    private function encodeHeader(string $header): string
    {
        if ($this->isAscii($header)) {
            return $header;
        }

        return sprintf('=?UTF-8?B?%s?=', base64_encode($header));
    }

    private function isAscii(string $string): bool
    {
        return !preg_match('/[^\x20-\x7E]/', $string);
    }

    private function base64Encode(string $content): string
    {
        return chunk_split(base64_encode($content));
    }
}
