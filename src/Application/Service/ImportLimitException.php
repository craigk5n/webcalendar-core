<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

/**
 * Exception thrown when import limits are exceeded.
 */
final class ImportLimitException extends \RuntimeException
{
  public static function contentTooLarge(int $size, int $maxSize): self
  {
    return new self(sprintf(
      'Import content size (%d bytes) exceeds maximum allowed (%d bytes)',
      $size,
      $maxSize
    ));
  }

  public static function tooManyEvents(int $count, int $maxEvents): self
  {
    return new self(sprintf(
      'Import contains %d events, maximum allowed is %d',
      $count,
      $maxEvents
    ));
  }
}
