<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Infrastructure\Security;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Infrastructure\Security\PlainTextHtmlSanitizer;

final class PlainTextHtmlSanitizerTest extends TestCase
{
    private PlainTextHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new PlainTextHtmlSanitizer();
    }

    public function testLeavesOrdinaryTextAlone(): void
    {
        $this->assertSame('Alice Smith', $this->sanitizer->sanitize('Alice Smith'));
        $this->assertSame('alice@example.com', $this->sanitizer->sanitize('alice@example.com'));
    }

    public function testStripsMarkup(): void
    {
        $this->assertSame('alert(1)', $this->sanitizer->sanitize('<script>alert(1)</script>'));
        $this->assertSame('', $this->sanitizer->sanitize('<img src=x onerror=alert(1)>'));
        $this->assertSame('Bold', $this->sanitizer->sanitize('<b>Bold</b>'));
    }

    /**
     * A single strip_tags() pass would leave "&lt;script&gt;" intact, ready to
     * become live markup the moment anything downstream decoded it.
     */
    public function testStripsMarkupThatArrivesEncoded(): void
    {
        $result = $this->sanitizer->sanitize('&lt;script&gt;alert(1)&lt;/script&gt;');

        $this->assertSame('alert(1)', $result);
        $this->assertStringNotContainsString('<script', $result);
    }

    public function testStripsControlCharactersThatWouldBreakSerialization(): void
    {
        $this->assertSame('ab', $this->sanitizer->sanitize("a\x00\x07b"));
    }

    public function testPreservesNewlinesAndTabsInsideTheValue(): void
    {
        $this->assertSame("line one\n\tline two", $this->sanitizer->sanitize("line one\n\tline two"));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $this->assertSame('Alice', $this->sanitizer->sanitize("  Alice \n"));
    }

    public function testHandlesEmptyInput(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(''));
    }

    /**
     * Deeply nested encoding must terminate rather than spin: after the pass
     * cap the value is returned as-is, and what remains is inert text.
     */
    public function testTerminatesOnDeeplyNestedEncoding(): void
    {
        $payload = str_repeat('&amp;', 40) . 'lt;script&gt;';

        $result = $this->sanitizer->sanitize($payload);

        $this->assertStringNotContainsString('<script', $result);
    }

    public function testPreservesUnicode(): void
    {
        $this->assertSame('Zoë Müller 日本', $this->sanitizer->sanitize('Zoë Müller 日本'));
    }
}
