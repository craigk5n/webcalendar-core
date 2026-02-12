<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\ViewService;
use WebCalendar\Core\Domain\Repository\ViewRepositoryInterface;
use WebCalendar\Core\Domain\Entity\View;
use WebCalendar\Core\Domain\ValueObject\ViewType;

final class ViewServiceTest extends TestCase
{
    /** @var ViewRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $viewRepository;
    private ViewService $viewService;

    protected function setUp(): void
    {
        $this->viewRepository = $this->createMock(ViewRepositoryInterface::class);
        $this->viewService = new ViewService($this->viewRepository);
    }

    public function testGetViewsForUser(): void
    {
        $login = 'jdoe';
        $globalView = new View(1, 'admin', 'Global View', ViewType::MONTH, true);
        $userView = new View(2, $login, 'My View', ViewType::WEEK, false);

        $this->viewRepository->expects($this->once())
            ->method('findAllGlobal')
            ->willReturn([$globalView]);

        $this->viewRepository->expects($this->once())
            ->method('findByOwner')
            ->with($login)
            ->willReturn([$userView]);

        $result = $this->viewService->getViewsForUser($login);
        
        $this->assertCount(2, $result);
        $this->assertContains($globalView, $result);
        $this->assertContains($userView, $result);
    }
}
