<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Webhook;

use WebCalendar\Core\Application\Contract\WebhookProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Webhook provider that logs webhooks instead of sending them.
 * Useful for development and testing environments.
 */
final readonly class LogWebhookProvider implements WebhookProviderInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function trigger(string $url, array $payload): void
    {
        $this->logger->info('Webhook would be triggered', [
            'url' => $url,
            'payload' => $payload,
        ]);
    }
}
