<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\CategoryService;
use WebCalendar\Core\Domain\Repository\CategoryRepositoryInterface;
use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\ValueObject\EventId;

final class CategoryServiceTest extends TestCase
{
    /** @var CategoryRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $categoryRepository;
    private CategoryService $categoryService;

    protected function setUp(): void
    {
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->categoryService = new CategoryService($this->categoryRepository);
    }

    public function testGetCategoriesForUser(): void
    {
        $login = 'jdoe';
        $globalCat = new Category(1, null, 'Global', '#000000');
        $userCat = new Category(2, $login, 'Personal', '#FFFFFF');

        $this->categoryRepository->expects($this->once())
            ->method('findAllGlobal')
            ->willReturn([$globalCat]);

        $this->categoryRepository->expects($this->once())
            ->method('findByOwner')
            ->with($login)
            ->willReturn([$userCat]);

        $result = $this->categoryService->getCategoriesForUser($login);
        
        $this->assertCount(2, $result);
        $this->assertContains($globalCat, $result);
        $this->assertContains($userCat, $result);
    }

    public function testAssignToEvent(): void
    {
        $eventId = new EventId(123);
        $login = 'jdoe';
        $catIds = [1, 2];
        $user = new \WebCalendar\Core\Domain\Entity\User($login, 'John', 'Doe', 'john@example.com', false, true);

        $this->categoryRepository->expects($this->once())
            ->method('assignToEvent')
            ->with($eventId, $login, $catIds);

        $this->categoryService->assignToEvent($eventId, $login, $catIds, $user);
    }
}
