<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Sentinel meaning "this argument was not supplied".
 *
 * Copy APIs such as {@see \WebCalendar\Core\Domain\Entity\Event::with()} have
 * to tell "leave this field as it is" apart from "set this field to null".
 * A nullable parameter defaulting to null cannot express both, which would
 * make clearing an optional field — a venue, a meeting link — impossible.
 * Using a dedicated type as the default keeps the distinction while leaving
 * every parameter fully typed for static analysis, unlike a `mixed` sentinel.
 */
enum Unchanged
{
    case Value;
}
