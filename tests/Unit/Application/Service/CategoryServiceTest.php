<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\CategoryService;
use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Repository\CategoryRepositoryInterface;
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

  private function createUser(string $login, bool $isAdmin = false): User
  {
    return new User($login, 'First', 'Last', "$login@example.com", $isAdmin, true);
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
    $user = $this->createUser($login);

    $this->categoryRepository->expects($this->once())
      ->method('assignToEvent')
      ->with($eventId, $login, $catIds);

    $this->categoryService->assignToEvent($eventId, $login, $catIds, $user);
  }

  public function testNonOwnerCannotAssignToOthersEvent(): void
  {
    $eventId = new EventId(123);
    $actor = $this->createUser('other');

    $this->categoryRepository->expects($this->never())
      ->method('assignToEvent');

    $this->expectException(AuthorizationException::class);
    $this->categoryService->assignToEvent($eventId, 'jdoe', [1], $actor);
  }

  public function testAdminCanAssignToAnyEvent(): void
  {
    $eventId = new EventId(123);
    $admin = $this->createUser('admin', isAdmin: true);

    $this->categoryRepository->expects($this->once())
      ->method('assignToEvent')
      ->with($eventId, 'jdoe', [1]);

    $this->categoryService->assignToEvent($eventId, 'jdoe', [1], $admin);
  }

  public function testOwnerCanUpdateOwnCategory(): void
  {
    $category = new Category(1, 'jdoe', 'My Cat', '#FF0000');
    $actor = $this->createUser('jdoe');

    $this->categoryRepository->expects($this->once())
      ->method('save')
      ->with($category);

    $this->categoryService->updateCategory($category, $actor);
  }

  public function testNonOwnerCannotUpdateCategory(): void
  {
    $category = new Category(1, 'jdoe', 'My Cat', '#FF0000');
    $actor = $this->createUser('other');

    $this->categoryRepository->expects($this->never())
      ->method('save');

    $this->expectException(AuthorizationException::class);
    $this->categoryService->updateCategory($category, $actor);
  }

  public function testAdminCanUpdateAnyCategory(): void
  {
    $category = new Category(1, 'jdoe', 'My Cat', '#FF0000');
    $admin = $this->createUser('admin', isAdmin: true);

    $this->categoryRepository->expects($this->once())
      ->method('save')
      ->with($category);

    $this->categoryService->updateCategory($category, $admin);
  }

  public function testNonAdminCannotUpdateGlobalCategory(): void
  {
    $globalCategory = new Category(1, null, 'Global', '#000000');
    $actor = $this->createUser('jdoe');

    $this->categoryRepository->expects($this->never())
      ->method('save');

    $this->expectException(AuthorizationException::class);
    $this->categoryService->updateCategory($globalCategory, $actor);
  }

  public function testAdminCanUpdateGlobalCategory(): void
  {
    $globalCategory = new Category(1, null, 'Global', '#000000');
    $admin = $this->createUser('admin', isAdmin: true);

    $this->categoryRepository->expects($this->once())
      ->method('save')
      ->with($globalCategory);

    $this->categoryService->updateCategory($globalCategory, $admin);
  }

  public function testOwnerCanDeleteOwnCategory(): void
  {
    $category = new Category(5, 'jdoe', 'My Cat', '#FF0000');
    $actor = $this->createUser('jdoe');

    $this->categoryRepository->expects($this->once())
      ->method('findById')
      ->with(5)
      ->willReturn($category);

    $this->categoryRepository->expects($this->once())
      ->method('delete')
      ->with(5);

    $this->categoryService->deleteCategory(5, $actor);
  }

  public function testNonOwnerCannotDeleteCategory(): void
  {
    $category = new Category(5, 'jdoe', 'My Cat', '#FF0000');
    $actor = $this->createUser('other');

    $this->categoryRepository->expects($this->once())
      ->method('findById')
      ->with(5)
      ->willReturn($category);

    $this->categoryRepository->expects($this->never())
      ->method('delete');

    $this->expectException(AuthorizationException::class);
    $this->categoryService->deleteCategory(5, $actor);
  }

  public function testDeleteNonExistentCategoryThrows(): void
  {
    $actor = $this->createUser('jdoe');

    $this->categoryRepository->expects($this->once())
      ->method('findById')
      ->with(999)
      ->willReturn(null);

    $this->categoryRepository->expects($this->never())
      ->method('delete');

    $this->expectException(\DomainException::class);
    $this->categoryService->deleteCategory(999, $actor);
  }
}
