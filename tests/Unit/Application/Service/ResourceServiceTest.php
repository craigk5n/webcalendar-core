<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\ResourceService;
use WebCalendar\Core\Domain\Repository\ResourceRepositoryInterface;
use WebCalendar\Core\Domain\Entity\Resource;

final class ResourceServiceTest extends TestCase
{
    /** @var ResourceRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $resourceRepository;
    private ResourceService $resourceService;

    protected function setUp(): void
    {
        $this->resourceRepository = $this->createMock(ResourceRepositoryInterface::class);
        $this->resourceService = new ResourceService($this->resourceRepository);
    }

    public function testGetAllResources(): void
    {
        $resource = new Resource('room1', 'Room 1', 'admin');
        
        $this->resourceRepository->expects($this->once())
            ->method('findAll')
            ->willReturn([$resource]);

        $result = $this->resourceService->getAllResources();
        $this->assertCount(1, $result);
        $this->assertSame($resource, $result[0]);
    }
}
