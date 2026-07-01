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
  ) {
  }
}
