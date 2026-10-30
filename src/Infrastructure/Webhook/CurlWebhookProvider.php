<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Webhook;

use WebCalendar\Core\Application\Contract\WebhookException;
use WebCalendar\Core\Application\Contract\WebhookProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * cURL-based webhook provider with SSRF protection.
 *
 * Unless private addresses are explicitly allowed (or an allowlist is
 * configured), the host is resolved before the request, every resolved
 * address is checked, and cURL is pinned to the checked address with
 * CURLOPT_RESOLVE. Checking the URL text alone is not enough: hosts such
 * as `2130706433`, `127.1`, `[::ffff:7f00:1]`, or a DNS name pointing at
 * an internal address all reach loopback or private networks.
 */
final class CurlWebhookProvider implements WebhookProviderInterface
{
    private readonly LoggerInterface $logger;

    /** @var \Closure(string): list<string> */
    private readonly \Closure $resolver;

    /**
     * @param string $secret Secret key for signing webhooks
     * @param int $timeout Request timeout in seconds
     * @param array<string> $allowedHosts Whitelist of allowed hosts (empty = all non-private)
     * @param bool $allowPrivateIps Whether to allow private IP ranges
     * @param (\Closure(string): list<string>)|null $resolver Resolves a host
     *        name to its IP addresses; defaults to the system resolver
     */
    public function __construct(
        private readonly string $secret = '',
        private readonly int $timeout = 10,
        private readonly array $allowedHosts = [],
        private readonly bool $allowPrivateIps = false,
        ?LoggerInterface $logger = null,
        ?\Closure $resolver = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->resolver = $resolver ?? self::systemResolve(...);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function trigger(string $url, array $payload): void
    {
        $pinnedResolve = $this->validateUrl($url);

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
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            if ($pinnedResolve !== null) {
                // Connect only to the address that was checked, so DNS
                // cannot hand cURL a different one (rebinding).
                curl_setopt($ch, CURLOPT_RESOLVE, [$pinnedResolve]);
            }

            curl_exec($ch);
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
     *
     * @return string|null A CURLOPT_RESOLVE entry ("host:port:address")
     *         pinning the request to the checked address, or null when no
     *         address check applies (allowlist or private IPs allowed).
     */
    private function validateUrl(string $url): ?string
    {
        $parsed = parse_url($url);

        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            throw WebhookException::blockedUrl($url, 'Invalid URL format');
        }

        // Credentials or backslashes in the authority are a common way to
        // make URL parsers disagree about the host; no webhook needs them.
        if (isset($parsed['user']) || isset($parsed['pass']) || str_contains($url, '\\')) {
            throw WebhookException::blockedUrl($url, 'Credentials or backslashes not allowed');
        }

        // Only allow HTTP(S)
        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw WebhookException::blockedUrl($url, 'Only HTTP(S) protocols allowed');
        }

        $host = $parsed['host'];

        // If whitelist is set, only allow those hosts
        if (!empty($this->allowedHosts)) {
            $allowed = array_map('strtolower', $this->allowedHosts);
            if (!in_array(strtolower($host), $allowed, true)) {
                throw WebhookException::blockedUrl($url, 'Host not in whitelist');
            }
            return null;
        }

        if ($this->allowPrivateIps) {
            return null;
        }

        $this->blockInternalNames($url, $host);

        // cURL matches CURLOPT_RESOLVE against the host exactly as written
        // in the URL, so pin that form; resolve the canonical (dotless) name.
        $urlHost = trim($host, '[]');
        $bareHost = rtrim($urlHost, '.');
        $addresses = filter_var($bareHost, FILTER_VALIDATE_IP) !== false
            ? [$bareHost]
            : ($this->resolver)($bareHost);
        if ($addresses === []) {
            throw WebhookException::blockedUrl($url, 'Host could not be resolved');
        }
        foreach ($addresses as $address) {
            if (!self::isPublicAddress($address)) {
                throw WebhookException::blockedUrl($url, 'Private/reserved IP range blocked');
            }
        }

        $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);
        $pinned = str_contains($addresses[0], ':') ? '[' . $addresses[0] . ']' : $addresses[0];

        return $urlHost . ':' . $port . ':' . $pinned;
    }

    /**
     * Fast rejection of names that are internal by convention.
     */
    private function blockInternalNames(string $url, string $host): void
    {
        $name = strtolower(rtrim($host, '.'));
        if ($name === 'localhost') {
            throw WebhookException::blockedUrl($url, 'Localhost blocked');
        }

        // Block internal DNS patterns
        if (preg_match('/\.(internal|local|localhost)$/', $name)) {
            throw WebhookException::blockedUrl($url, 'Internal DNS name blocked');
        }
    }

    /**
     * Address ranges that must never be reached, as [network, prefix length].
     * IPv6 forms that embed an IPv4 address are unwrapped first and checked
     * against the IPv4 list, so only native IPv6 ranges are listed here.
     */
    private const BLOCKED_IPV4 = [
        ['0.0.0.0', 8],        // "this" network
        ['10.0.0.0', 8],       // private
        ['100.64.0.0', 10],    // carrier-grade NAT
        ['127.0.0.0', 8],      // loopback
        ['169.254.0.0', 16],   // link-local, cloud metadata
        ['172.16.0.0', 12],    // private
        ['192.0.0.0', 24],     // IETF protocol assignments
        ['192.0.2.0', 24],     // documentation
        ['192.88.99.0', 24],   // 6to4 relay anycast
        ['192.168.0.0', 16],   // private
        ['198.18.0.0', 15],    // benchmarking
        ['198.51.100.0', 24],  // documentation
        ['203.0.113.0', 24],   // documentation
        ['224.0.0.0', 3],      // multicast, reserved, broadcast
    ];

    private const BLOCKED_IPV6 = [
        ['::', 128],           // unspecified
        ['::1', 128],          // loopback
        ['100::', 64],         // discard
        ['2001::', 32],        // Teredo (embeds an obfuscated IPv4)
        ['2001:db8::', 32],    // documentation
        ['fc00::', 7],         // unique local
        ['fe80::', 10],        // link-local
        ['fec0::', 10],        // site-local (deprecated)
        ['ff00::', 8],         // multicast
    ];

    /**
     * True only for a routable public address.
     */
    private static function isPublicAddress(string $address): bool
    {
        $binary = @inet_pton($address);
        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 16) {
            $embedded = self::embeddedIpv4($binary);
            if ($embedded !== null) {
                $binary = $embedded;
            } else {
                return !self::inRanges($binary, self::BLOCKED_IPV6);
            }
        }

        return !self::inRanges($binary, self::BLOCKED_IPV4);
    }

    /**
     * Returns the IPv4 address carried inside an IPv6 address by the
     * IPv4-mapped (::ffff:0:0/96), IPv4-compatible (::/96), NAT64
     * (64:ff9b::/96) and 6to4 (2002::/16) forms, or null for native IPv6.
     */
    private static function embeddedIpv4(string $binary): ?string
    {
        $zeros = str_repeat("\0", 10);
        if (str_starts_with($binary, $zeros . "\xff\xff")
            || str_starts_with($binary, $zeros . "\0\0")
            || str_starts_with($binary, "\x00\x64\xff\x9b" . str_repeat("\0", 8))
        ) {
            return substr($binary, 12);
        }
        if (str_starts_with($binary, "\x20\x02")) {
            return substr($binary, 2, 4);
        }

        return null;
    }

    /**
     * @param list<array{string, int}> $ranges
     */
    private static function inRanges(string $binary, array $ranges): bool
    {
        foreach ($ranges as [$network, $prefix]) {
            $net = (string) inet_pton($network);
            $bytes = intdiv($prefix, 8);
            $bits = $prefix % 8;
            if (strncmp($binary, $net, $bytes) !== 0) {
                continue;
            }
            if ($bits === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $bits)) & 0xFF;
            if ((ord($binary[$bytes]) & $mask) === (ord($net[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves a host name to its IPv4 and IPv6 addresses.
     *
     * @return list<string>
     */
    private static function systemResolve(string $host): array
    {
        $ipv4 = gethostbynamel($host);
        $addresses = $ipv4 === false ? [] : $ipv4;
        $records = @dns_get_record($host, DNS_AAAA);
        foreach ($records === false ? [] : $records as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
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
