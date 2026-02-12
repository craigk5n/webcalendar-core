<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\BlobService;
use WebCalendar\Core\Domain\Repository\BlobRepositoryInterface;
use WebCalendar\Core\Domain\Entity\Blob;
use WebCalendar\Core\Domain\ValueObject\BlobType;

final class BlobServiceTest extends TestCase
{
    /** @var BlobRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $blobRepository;
    private BlobService $blobService;

    protected function setUp(): void
    {
        $this->blobRepository = $this->createMock(BlobRepositoryInterface::class);
        $this->blobService = new BlobService($this->blobRepository);
    }

    public function testGetAttachmentsForEvent(): void
    {
        $eventId = 123;
        $blob = new Blob(1, $eventId, 'jdoe', 'file.txt', 'desc', 10, 'text/plain', BlobType::ATTACHMENT, new \DateTimeImmutable());

        $this->blobRepository->expects($this->once())
            ->method('findByEvent')
            ->with($eventId, BlobType::ATTACHMENT)
            ->willReturn([$blob]);

        $result = $this->blobService->getAttachmentsForEvent($eventId);
        $this->assertCount(1, $result);
        $this->assertSame($blob, $result[0]);
    }
}
