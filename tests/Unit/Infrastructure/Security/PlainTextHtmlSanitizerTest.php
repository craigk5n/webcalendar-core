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

    /**
     * Browsers decode character references that are missing their trailing
     * ";" -- unterminated numeric/hex forms, and the legacy named set that
     * includes lt/gt/amp/quot.  html_entity_decode() refuses to, so without
     * normalization these slipped through the decode/strip loop untouched and
     * the sanitizer returned markup-shaped text it claimed to have removed.
     *
     * Expectations differ per payload because strip_tags() keeps the text
     * inside a stripped element but discards everything in an unterminated
     * one -- both outcomes are markup-free, which is the point.
     *
     * @dataProvider semicolonlessReferences
     */
    public function testStripsMarkupWrittenWithSemicolonlessReferences(
        string $payload,
        string $expected
    ): void {
        $result = $this->sanitizer->sanitize($payload);

        $this->assertSame($expected, $result);
        $this->assertStringNotContainsString('script', strtolower($result));
        $this->assertStringNotContainsString('<', $result);
    }

    /** @return array<string, string[]> */
    public static function semicolonlessReferences(): array
    {
        return [
            'decimal'      => ['&#60script&#62alert(1)&#60/script&#62', 'alert(1)'],
            'hex lower'    => ['&#x3cimg src=x onerror=alert(1)&#x3e', ''],
            'hex upper'    => ['&#X3Cimg src=x onerror=alert(1)&#X3E', ''],
            'named legacy' => ['&ltscript&gtalert(1)&lt/script&gt', 'alert(1)'],
            'named upper'  => ['&LTscript&GTalert(1)&LT/script&GT', 'alert(1)'],
            'mixed forms'  => ['&#60script&gtalert(1)&lt/script&#62', 'alert(1)'],
        ];
    }

    /**
     * Normalization must not rewrite text that merely looks entity-ish.
     */
    public function testLeavesAmpersandsInOrdinaryTextAlone(): void
    {
        $this->assertSame('Tom & Jerry', $this->sanitizer->sanitize('Tom & Jerry'));
        $this->assertSame('AT&T meeting', $this->sanitizer->sanitize('AT&T meeting'));
        $this->assertSame('a&b=1&c=2', $this->sanitizer->sanitize('a&b=1&c=2'));
        $this->assertSame('100% & rising', $this->sanitizer->sanitize('100% & rising'));
    }

    /**
     * Whatever comes back must not turn into markup if a downstream renderer
     * decodes it again -- that extra decode is the whole reason this class
     * loops rather than stripping once.
     */
    public function testOutputStaysInertUnderFurtherDecoding(): void
    {
        $payloads = [
            '<script>alert(1)</script>',
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            '&#60script&#62alert(1)&#60/script&#62',
            '&ltscript&gtalert(1)&lt/script&gt',
            '<scr<script>ipt>alert(1)</scr</script>ipt>',
            '&#x3cimg src=x onerror=alert(1)&#x3e',
            '&LTscript&GTalert(1)&LT/script&GT',
        ];

        foreach ($payloads as $payload) {
            $decoded = $this->sanitizer->sanitize($payload);
            for ($i = 0; $i < 3; $i++) {
                $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $this->assertDoesNotMatchRegularExpression(
                    '/<\s*[a-zA-Z\/!]/',
                    $decoded,
                    sprintf('payload %s resurrected markup after %d decode(s)', $payload, $i + 1)
                );
            }
        }
    }
}
