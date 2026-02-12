<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Contract;

/**
 * Interface for triggering outbound webhooks.
 */
interface WebhookProviderInterface
{
    /**
     * Sends a webhook notification.
     * 
     * @param array<string, mixed> $payload
     */
    public function trigger(string $url, array $payload): void;
}
