<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\DTO;

/**
 * Result of an iCal import operation.
 */
final class ImportResult
{
  public function __construct(
    public readonly int $importedCount,
    public readonly int $skippedCount,
    /** @var array<array{line: int, message: string}> */
    public readonly array $warnings = [],
    /**
     * Events overwritten because their UID already existed and the caller
     * asked for updates. Always 0 when importing in the default skip mode.
     *
     * Declared last, after $warnings, so existing positional callers keep
     * working unchanged.
     */
    public readonly int $updatedCount = 0,
  ) {
  }
}
