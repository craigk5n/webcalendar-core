<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Contract;

/**
 * Exception thrown when webhook fails.
 */
final class WebhookException extends \RuntimeException
{
    public static function connectionFailed(string $url, string $reason): self
    {
        return new self(sprintf('Webhook connection to %s failed: %s', $url, $reason));
    }

    public static function blockedUrl(string $url, string $reason): self
    {
        return new self(sprintf('Webhook URL blocked: %s (%s)', $url, $reason));
    }

    public static function invalidResponse(string $url, int $statusCode): self
    {
        return new self(sprintf('Webhook to %s returned status %d', $url, $statusCode));
    }
}
