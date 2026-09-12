<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Security;

use WebCalendar\Core\Application\Contract\HtmlSanitizerInterface;

/**
 * Default sanitizer: reduces untrusted input to plain text.
 *
 * Markup is removed rather than escaped.  A stored value is rendered in more
 * than one context -- HTML in a web client, plain text in an iCalendar or RSS
 * export -- and escaped entities are only correct in one of them, whereas
 * plain text is correct in all of them.
 *
 * Consumers that genuinely need rich descriptions should wire their own
 * allow-list implementation of {@see HtmlSanitizerInterface} instead.
 */
final class PlainTextHtmlSanitizer implements HtmlSanitizerInterface
{
    /**
     * Cap on decode/strip rounds, so a deeply nested payload cannot spin here.
     */
    private const MAX_PASSES = 5;

    /**
     * Named references a browser decodes even without the trailing ";".
     *
     * The HTML5 legacy set is longer than this, but these are the ones that
     * can produce markup delimiters; the rest decode to harmless glyphs.
     */
    private const LEGACY_NAMED = 'lt|LT|gt|GT|amp|AMP|quot|QUOT';

    public function sanitize(string $html): string
    {
        $value = $html;
        $previous = '';
        $passes = 0;

        // Decode, then strip, repeatedly until the string stops changing:
        // a single pass would let "&lt;script&gt;" through as live markup the
        // moment something downstream decoded it.
        while ($value !== $previous && $passes < self::MAX_PASSES) {
            $previous = $value;
            $value = strip_tags(
                html_entity_decode($this->terminateReferences($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            );
            $passes++;
        }

        // Control characters would corrupt an iCalendar or RSS serialization.
        $value = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return trim($value);
    }

    /**
     * Supplies the trailing ";" that html_entity_decode() insists on.
     *
     * A browser following the HTML5 tokenizer decodes "&#60script" and
     * "&ltscript" -- unterminated numeric references, and the legacy named
     * set -- but html_entity_decode() returns them untouched. Left alone they
     * sail through the decode/strip loop above, so the sanitizer would hand
     * back markup-shaped text it is supposed to have removed.
     *
     * Only sequences that already look like a reference are rewritten, so
     * ordinary prose ("Tom & Jerry", "a&b=1") is left exactly as it was.
     */
    private function terminateReferences(string $value): string
    {
        $patterns = [
            // &#x3c / &#X3C  (hex, 1-6 digits)
            '/&#[xX]([0-9a-fA-F]{1,6})(?![0-9a-fA-F;])/' => '&#x$1;',
            // &#60  (decimal, 1-7 digits)
            '/&#([0-9]{1,7})(?![0-9;])/' => '&#$1;',
            // &lt / &GT / &amp / &quot
            // No alphanumeric guard here: a browser matching the legacy set
            // decodes "&ltscript" to "<script", so this must too.
            '/&(' . self::LEGACY_NAMED . ')(?!;)/' => '&$1;',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $value = (string)preg_replace($pattern, $replacement, $value);
        }

        return $value;
    }
}
