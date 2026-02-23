<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Contract;

/**
 * Interface for sending emails.
 */
interface EmailProviderInterface
{
    /**
     * Sends an email message.
     *
     * @param EmailMessage $message The email to send
     * @throws EmailException If the email cannot be sent
     */
    public function send(EmailMessage $message): void;

    /**
     * Sends a simple email (convenience method).
     *
     * @throws EmailException If the email cannot be sent
     */
    public function sendSimple(string $to, string $subject, string $body): void;
}
