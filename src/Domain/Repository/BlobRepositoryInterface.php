<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\Entity\Blob;
use WebCalendar\Core\Domain\ValueObject\BlobType;

/**
 * Interface for Blob persistence operations.
 */
interface BlobRepositoryInterface
{
    public function findById(int $id): ?Blob;

    /**
     * @return Blob[]
     */
    public function findByEvent(int $eventId, ?BlobType $type = null): array;

    public function save(Blob $blob): void;

    public function delete(int $id): void;
}
