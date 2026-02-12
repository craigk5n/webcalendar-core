<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\LayerService;
use WebCalendar\Core\Domain\Repository\LayerRepositoryInterface;
use WebCalendar\Core\Domain\Entity\Layer;

final class LayerServiceTest extends TestCase
{
    /** @var LayerRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $layerRepository;
    private LayerService $layerService;

    protected function setUp(): void
    {
        $this->layerRepository = $this->createMock(LayerRepositoryInterface::class);
        $this->layerService = new LayerService($this->layerRepository);
    }

    public function testGetLayersForUser(): void
    {
        $login = 'jdoe';
        $layer = new Layer(1, $login, 'asmith', '#FF0000');

        $this->layerRepository->expects($this->once())
            ->method('findByOwner')
            ->with($login)
            ->willReturn([$layer]);

        $result = $this->layerService->getLayersForUser($login);
        
        $this->assertCount(1, $result);
        $this->assertSame($layer, $result[0]);
    }
}
