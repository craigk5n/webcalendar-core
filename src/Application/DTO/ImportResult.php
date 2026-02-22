<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\DTO;

/**
 * Result of an iCal import operation.
 */
final readonly class ImportResult
{
  public function __construct(
    public int $importedCount,
    public int $skippedCount,
    /** @var array<array{line: int, message: string}> */
    public array $warnings = [],
  ) {
  }
}
