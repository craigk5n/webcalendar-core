<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Contract;

/**
 * Interface for sanitizing untrusted text before it is persisted.
 *
 * Core builds a handful of strings out of caller-supplied fragments (a public
 * booking name and email address, for example).  Those fragments reach the
 * library straight from an anonymous HTTP request in the consuming
 * application, and are later rendered into an authenticated user's calendar.
 * Implementations decide what "safe" means for their rendering surface --
 * escaping, tag stripping, or an allow-list HTML sanitizer.
 */
interface HtmlSanitizerInterface
{
    /**
     * Returns a version of $html that is safe to store and render.
     */
    public function sanitize(string $html): string;
}
