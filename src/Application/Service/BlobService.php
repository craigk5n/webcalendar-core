<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Blob;
use WebCalendar\Core\Domain\Repository\BlobRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\BlobType;

/**
 * Service for managing event attachments and comments.
 */
final readonly class BlobService
{
    public function __construct(
        private BlobRepositoryInterface $blobRepository
    ) {
    }

    /**
     * @return Blob[]
     */
    public function getAttachmentsForEvent(int $eventId): array
    {
        return $this->blobRepository->findByEvent($eventId, BlobType::ATTACHMENT);
    }

    /**
     * @return Blob[]
     */
    public function getCommentsForEvent(int $eventId): array
    {
        return $this->blobRepository->findByEvent($eventId, BlobType::COMMENT);
    }

    public function addBlob(Blob $blob): void
    {
        $this->blobRepository->save($blob);
    }

    public function deleteBlob(int $id): void
    {
        $this->blobRepository->delete($id);
    }

    public function getBlobById(int $id): ?Blob
    {
        return $this->blobRepository->findById($id);
    }
}
