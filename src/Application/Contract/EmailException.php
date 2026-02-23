<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Contract;

/**
 * Exception thrown when email sending fails.
 */
final class EmailException extends \RuntimeException
{
    public static function sendFailed(string $to, string $reason): self
    {
        return new self(sprintf('Failed to send email to %s: %s', $to, $reason));
    }

    public static function invalidRecipient(string $to): self
    {
        return new self(sprintf('Invalid email recipient: %s', $to));
    }

    public static function configurationError(string $message): self
    {
        return new self(sprintf('Email configuration error: %s', $message));
    }
}
