<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Webhook;

use WebCalendar\Core\Application\Contract\WebhookException;
use WebCalendar\Core\Application\Contract\WebhookProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * cURL-based webhook provider with SSRF protection.
 */
final readonly class CurlWebhookProvider implements WebhookProviderInterface
{
    private LoggerInterface $logger;

    /**
     * @param string $secret Secret key for signing webhooks
     * @param int $timeout Request timeout in seconds
     * @param array<string> $allowedHosts Whitelist of allowed hosts (empty = all non-private)
     * @param bool $allowPrivateIps Whether to allow private IP ranges
     */
    public function __construct(
        private string $secret = '',
        private int $timeout = 10,
        private array $allowedHosts = [],
        private bool $allowPrivateIps = false,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function trigger(string $url, array $payload): void
    {
        $this->validateUrl($url);

        $payloadJson = json_encode($payload);
        if ($payloadJson === false) {
            throw new \InvalidArgumentException('Failed to encode webhook payload');
        }

        $signature = $this->generateSignature($payloadJson);

        $this->logger->info('Triggering webhook', ['url' => $url]);

        $ch = curl_init($url);
        if ($ch === false) {
            throw WebhookException::connectionFailed($url, 'Failed to initialize cURL');
        }

        try {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payloadJson,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-WebCalendar-Signature: ' . $signature,
                    'X-WebCalendar-Timestamp: ' . time(),
                ],
                CURLOPT_FOLLOWLOCATION => false, // Prevent redirect to private IPs
                CURLOPT_MAXREDIRS => 0,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($error !== '') {
                $this->logger->error('Webhook failed', ['url' => $url, 'error' => $error]);
                throw WebhookException::connectionFailed($url, $error);
            }

            if ($httpCode >= 400) {
                $this->logger->warning('Webhook returned error status', ['url' => $url, 'status' => $httpCode]);
                throw WebhookException::invalidResponse($url, (int)$httpCode);
            }

            $this->logger->info('Webhook triggered successfully', ['url' => $url, 'status' => $httpCode]);
        } finally {
            curl_close($ch);
        }
    }

    /**
     * Validates the webhook URL to prevent SSRF attacks.
     */
    private function validateUrl(string $url): void
    {
        $parsed = parse_url($url);

        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            throw WebhookException::blockedUrl($url, 'Invalid URL format');
        }

        // Only allow HTTP(S)
        if (!in_array($parsed['scheme'], ['http', 'https'], true)) {
            throw WebhookException::blockedUrl($url, 'Only HTTP(S) protocols allowed');
        }

        $host = $parsed['host'];

        // If whitelist is set, only allow those hosts
        if (!empty($this->allowedHosts)) {
            if (!in_array($host, $this->allowedHosts, true)) {
                throw WebhookException::blockedUrl($url, 'Host not in whitelist');
            }
            return;
        }

        // Block private IP ranges unless explicitly allowed
        if (!$this->allowPrivateIps) {
            $this->blockPrivateIp($url, $host);
        }
    }

    /**
     * Blocks requests to private IP addresses.
     */
    private function blockPrivateIp(string $url, string $host): void
    {
        // Block localhost
        if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            throw WebhookException::blockedUrl($url, 'Localhost blocked');
        }

        // Block private IP ranges
        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip !== false) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw WebhookException::blockedUrl($url, 'Private/reserved IP range blocked');
            }
        }

        // Block cloud metadata endpoints
        if (preg_match('/^169\.254\.\d+\.\d+$/', $host)) {
            throw WebhookException::blockedUrl($url, 'Cloud metadata endpoint blocked');
        }

        // Block internal DNS patterns
        if (preg_match('/\.(internal|local|localhost)$/i', $host)) {
            throw WebhookException::blockedUrl($url, 'Internal DNS name blocked');
        }
    }

    /**
     * Generates an HMAC signature for the webhook payload.
     */
    private function generateSignature(string $payload): string
    {
        if ($this->secret === '') {
            return '';
        }

        return hash_hmac('sha256', $payload, $this->secret);
    }
}
