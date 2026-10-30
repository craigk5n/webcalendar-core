<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Infrastructure\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Contract\WebhookException;
use WebCalendar\Core\Infrastructure\Webhook\CurlWebhookProvider;

final class CurlWebhookProviderTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function loopbackAndPrivateUrls(): array
    {
        return [
            'dotted loopback' => ['http://127.0.0.1/hook'],
            'short loopback' => ['http://127.1/hook'],
            'decimal loopback' => ['http://2130706433/hook'],
            'hex loopback' => ['http://0x7f000001/hook'],
            'octal loopback' => ['http://0177.0.0.1/hook'],
            'zero address' => ['http://0/hook'],
            'ipv6 loopback' => ['http://[::1]/hook'],
            'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/hook'],
            'ipv4-mapped hex loopback' => ['http://[::ffff:7f00:1]/hook'],
            'localhost' => ['http://localhost/hook'],
            'localhost trailing dot' => ['http://localhost./hook'],
            'private 10/8' => ['http://10.0.0.1/hook'],
            'private 192.168/16' => ['https://192.168.1.10:8443/hook'],
            'link-local metadata' => ['http://169.254.169.254/latest'],
            'carrier-grade NAT' => ['http://100.64.0.1/hook'],
            'ipv6 unique local' => ['http://[fd00::1]/hook'],
            'internal suffix' => ['http://db.internal/hook'],
            'nat64 loopback' => ['http://[64:ff9b::7f00:1]/hook'],
            'nat64 metadata' => ['http://[64:ff9b::a9fe:a9fe]/hook'],
            '6to4 loopback' => ['http://[2002:7f00:1::]/hook'],
            'ipv4-compatible loopback' => ['http://[::7f00:1]/hook'],
            'ipv6 site-local' => ['http://[fec0::1]/hook'],
            'ipv6 multicast' => ['http://[ff02::1]/hook'],
            'ietf protocol assignments' => ['http://192.0.0.8/hook'],
            'benchmark network' => ['http://198.18.0.1/hook'],
            'ipv4 multicast' => ['http://224.0.0.1/hook'],
            'userinfo' => ['http://user@example.com/hook'],
            'backslash' => ['http://example.com\\@127.0.0.1/hook'],
        ];
    }

    #[DataProvider('loopbackAndPrivateUrls')]
    public function testBlocksLoopbackAndPrivateTargets(string $url): void
    {
        $provider = new CurlWebhookProvider(resolver: static fn (): array => []);

        // Must be refused by validation, never by a failed connection.
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('Webhook URL blocked');
        $this->validate($provider, $url);
    }

    public function testBlocksHostnameResolvingToPrivateAddress(): void
    {
        $provider = new CurlWebhookProvider(
            resolver: static fn (string $host): array => $host === 'hooks.example.test'
                ? ['203.0.113.10', '10.1.2.3']
                : []
        );

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('Private/reserved');
        $provider->trigger('https://hooks.example.test/x', []);
    }

    public function testBlocksUnresolvableHost(): void
    {
        $provider = new CurlWebhookProvider(resolver: static fn (): array => []);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('could not be resolved');
        $provider->trigger('https://nowhere.example.test/x', []);
    }

    public function testPinsPublicHostToItsCheckedAddress(): void
    {
        $provider = new CurlWebhookProvider(
            resolver: static fn (): array => ['93.184.215.14']
        );

        $this->assertSame(
            'hooks.example.com:443:93.184.215.14',
            $this->validate($provider, 'https://hooks.example.com/x')
        );
        $this->assertSame(
            'hooks.example.com:8080:93.184.215.14',
            $this->validate($provider, 'http://hooks.example.com:8080/x')
        );
    }

    public function testBlocksDnsAnswerInNat64Range(): void
    {
        $provider = new CurlWebhookProvider(
            resolver: static fn (): array => ['64:ff9b::a9fe:a9fe']
        );

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('Webhook URL blocked');
        $this->validate($provider, 'https://hooks.example.com/x');
    }

    public function testAllowsPublic6to4AndNat64Addresses(): void
    {
        $provider = new CurlWebhookProvider(
            resolver: static fn (): array => ['64:ff9b::5db8:d70e', '2002:5db8:d70e::1']
        );

        $this->assertSame(
            'hooks.example.com:443:[64:ff9b::5db8:d70e]',
            $this->validate($provider, 'https://hooks.example.com/x')
        );
    }

    public function testPinKeepsTrailingDotSoCurlMatchesTheUrlHost(): void
    {
        $provider = new CurlWebhookProvider(
            resolver: static fn (string $host): array => $host === 'hooks.example.com'
                ? ['93.184.215.14']
                : []
        );

        $this->assertSame(
            'hooks.example.com.:443:93.184.215.14',
            $this->validate($provider, 'https://hooks.example.com./x')
        );
    }

    public function testPinsIpv6AddressInBrackets(): void
    {
        $provider = new CurlWebhookProvider(
            resolver: static fn (): array => ['2606:4700::1111']
        );

        $this->assertSame(
            'hooks.example.com:443:[2606:4700::1111]',
            $this->validate($provider, 'https://hooks.example.com/x')
        );
    }

    public function testAllowlistIsCaseInsensitiveAndSkipsAddressChecks(): void
    {
        $provider = new CurlWebhookProvider(
            allowedHosts: ['Hooks.Example.com'],
            resolver: static fn (): array => []
        );

        $this->assertNull($this->validate($provider, 'https://hooks.example.com/x'));
    }

    public function testAllowPrivateIpsSkipsAddressChecks(): void
    {
        $provider = new CurlWebhookProvider(allowPrivateIps: true);

        $this->assertNull($this->validate($provider, 'http://127.0.0.1:9000/x'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ambiguousAuthorityUrls(): array
    {
        return [
            'userinfo' => ['https://user@hooks.example.com/x'],
            'user and password' => ['https://u:p@hooks.example.com/x'],
            'backslash' => ['https://hooks.example.com\\@other.example.com/x'],
        ];
    }

    #[DataProvider('ambiguousAuthorityUrls')]
    public function testRejectsCredentialsAndBackslashesEvenForPublicHosts(string $url): void
    {
        $provider = new CurlWebhookProvider(
            resolver: static fn (): array => ['93.184.215.14']
        );

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('Credentials or backslashes');
        $this->validate($provider, $url);
    }

    public function testRejectsNonHttpScheme(): void
    {
        $this->expectException(WebhookException::class);
        (new CurlWebhookProvider())->trigger('gopher://example.com/x', []);
    }

    private function validate(CurlWebhookProvider $provider, string $url): ?string
    {
        $method = new \ReflectionMethod($provider, 'validateUrl');

        /** @var string|null */
        return $method->invoke($provider, $url);
    }
}
