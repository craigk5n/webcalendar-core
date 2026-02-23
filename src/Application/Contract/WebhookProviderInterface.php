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
     * @param string $url The webhook endpoint URL
     * @param array<string, mixed> $payload The data to send
     * @throws WebhookException If the webhook delivery fails
     */
    public function trigger(string $url, array $payload): void;
}
