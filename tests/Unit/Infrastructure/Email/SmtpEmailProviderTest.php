<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Infrastructure\Email;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Contract\EmailException;
use WebCalendar\Core\Application\Contract\EmailMessage;
use WebCalendar\Core\Infrastructure\Email\SmtpEmailProvider;

/**
 * Header values are written into the mail() header block as-is, so a line
 * break in one would inject headers. Each send below is rejected before
 * mail() is reached.
 */
final class SmtpEmailProviderTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function badReplyTo(): array
    {
        return [
            'CRLF Bcc' => ["reply@example.test\r\nBcc: injected@example.test"],
            'bare LF' => ["reply@example.test\nX-Injected: yes"],
            'not an address' => ['not an address'],
            'two addresses' => ['a@example.test, b@example.test'],
        ];
    }

    #[DataProvider('badReplyTo')]
    public function testMessageReplyToMustBeOneAddress(string $replyTo): void
    {
        $provider = new SmtpEmailProvider('noreply@example.test', 'Cal');

        $this->expectException(EmailException::class);
        $this->expectExceptionMessage('Reply-To');
        $provider->send(new EmailMessage(
            to: 'alice@example.test',
            subject: 'Hello',
            htmlBody: '',
            textBody: 'body',
            replyTo: $replyTo,
        ));
    }

    #[DataProvider('badReplyTo')]
    public function testConfiguredReplyToMustBeOneAddress(string $replyTo): void
    {
        $this->expectException(EmailException::class);
        new SmtpEmailProvider('noreply@example.test', 'Cal', $replyTo);
    }

    public function testFromAddressMustNotContainLineBreaks(): void
    {
        $this->expectException(EmailException::class);
        new SmtpEmailProvider("noreply@example.test\r\nBcc: x@example.test");
    }

    public function testValidReplyToIsAccepted(): void
    {
        $provider = new SmtpEmailProvider('noreply@example.test', 'Cal', 'reply@example.test');

        $this->assertInstanceOf(SmtpEmailProvider::class, $provider);
    }
}
