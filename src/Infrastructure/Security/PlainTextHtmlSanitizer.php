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
                html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            );
            $passes++;
        }

        // Control characters would corrupt an iCalendar or RSS serialization.
        $value = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return trim($value);
    }
}
