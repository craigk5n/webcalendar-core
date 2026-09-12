<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use WebCalendar\Core\Domain\Entity\ActivityLogEntry;
use WebCalendar\Core\Domain\Repository\ActivityLogRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\DateRange;

/**
 * Test double that keeps whatever reaches the audit trail, so a test can
 * assert on it. Named rather than anonymous because the assertions read its
 * entries, which an interface-typed anonymous class will not expose.
 */
final class RecordingActivityLogRepository implements ActivityLogRepositoryInterface
{
    /** @var ActivityLogEntry[] */
    private array $entries = [];

    public function save(ActivityLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return ActivityLogEntry[]
     */
    public function findByDateRange(DateRange $range, ?string $login = null): array
    {
        return $this->entries;
    }

    /**
     * @return ActivityLogEntry[]
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
