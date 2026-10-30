<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\CategoryService;
use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Repository\CategoryRepositoryInterface;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\EventId;

final class CategoryServiceTest extends TestCase
{
  /** @var CategoryRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
  private $categoryRepository;
  /** @var EventRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
  private $eventRepository;
  private CategoryService $categoryService;

  protected function setUp(): void
  {
    $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
    $this->eventRepository = $this->createMock(EventRepositoryInterface::class);
    $this->categoryService = new CategoryService($this->categoryRepository, $this->eventRepository);
  }

  private function eventBy(int $id, string $creator): Event
  {
    return new Event(
      id: new EventId($id),
      uid: 'uid-' . $id,
      name: 'Event ' . $id,
      description: '',
      location: '',
      start: new \DateTimeImmutable('2030-01-01 10:00'),
      duration: 60,
      createdBy: $creator,
      type: EventType::EVENT,
      access: AccessLevel::PUBLIC,
    );
  }

  /** Category 1 is global, 2 belongs to jdoe, 5 belongs to alice. */
  private function seedCategories(): void
  {
    $this->categoryRepository->method('findByCompositeKey')->willReturnCallback(
      fn (int $id, string $owner): ?Category => match ([$id, $owner]) {
        [1, ''] => new Category(1, null, 'Global', null),
        [2, 'jdoe'] => new Category(2, 'jdoe', 'Mine', null),
        [5, 'alice'] => new Category(5, 'alice', 'Hers', null),
        default => null,
      }
    );
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
    $this->seedCategories();
    $this->eventRepository->method('findById')->willReturn($this->eventBy(123, $login));

    $this->categoryRepository->expects($this->once())
      ->method('assignToEvent')
      ->with($eventId, $login, $catIds);

    $this->categoryService->assignToEvent($eventId, $login, $catIds, $user);
  }

  public function testParticipantCanAssignToEventTheyAreOn(): void
  {
    $this->seedCategories();
    $this->eventRepository->method('findById')->willReturn($this->eventBy(7, 'alice'));
    $this->eventRepository->method('getParticipantsWithStatus')
      ->willReturn(['alice' => 'A', 'jdoe' => 'W']);

    $this->categoryRepository->expects($this->once())->method('assignToEvent');

    $this->categoryService->assignToEvent(new EventId(7), 'jdoe', [1], $this->createUser('jdoe'));
  }

  public function testParticipantWhoRejectedCannotAssign(): void
  {
    $this->seedCategories();
    $this->eventRepository->method('findById')->willReturn($this->eventBy(7, 'alice'));
    $this->eventRepository->method('getParticipantsWithStatus')
      ->willReturn(['alice' => 'A', 'jdoe' => 'R']);

    $this->categoryRepository->expects($this->never())->method('assignToEvent');

    $this->expectException(AuthorizationException::class);
    $this->categoryService->assignToEvent(new EventId(7), 'jdoe', [1], $this->createUser('jdoe'));
  }

  public function testNonParticipantCannotAssignToOthersEvent(): void
  {
    $this->seedCategories();
    $this->eventRepository->method('findById')->willReturn($this->eventBy(7, 'alice'));
    $this->eventRepository->method('getParticipantsWithStatus')->willReturn(['alice' => 'A']);

    $this->categoryRepository->expects($this->never())->method('assignToEvent');

    $this->expectException(AuthorizationException::class);
    $this->categoryService->assignToEvent(new EventId(7), 'jdoe', [1], $this->createUser('jdoe'));
  }

  public function testCannotAssignToMissingEvent(): void
  {
    $this->seedCategories();
    $this->eventRepository->method('findById')->willReturn(null);

    $this->categoryRepository->expects($this->never())->method('assignToEvent');

    $this->expectException(AuthorizationException::class);
    $this->categoryService->assignToEvent(new EventId(99), 'jdoe', [1], $this->createUser('jdoe'));
  }

  public function testCannotAssignAnotherUsersPersonalCategory(): void
  {
    $this->seedCategories();
    $this->eventRepository->method('findById')->willReturn($this->eventBy(8, 'jdoe'));

    $this->categoryRepository->expects($this->never())->method('assignToEvent');

    $this->expectException(AuthorizationException::class);
    $this->categoryService->assignToEvent(new EventId(8), 'jdoe', [1, 5], $this->createUser('jdoe'));
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

  public function testOwnerCanCreateOwnCategory(): void
  {
    $category = new Category(9, 'jdoe', 'New Cat', '#00FF00');
    $this->categoryRepository->method('findByCompositeKey')->willReturn(null);

    $this->categoryRepository->expects($this->once())
      ->method('save')
      ->with($category);

    $this->categoryService->createCategory($category, $this->createUser('jdoe'));
  }

  public function testNonOwnerCannotCreateCategoryForAnotherUser(): void
  {
    $this->categoryRepository->method('findByCompositeKey')->willReturn(null);
    $this->categoryRepository->expects($this->never())->method('save');

    $this->expectException(AuthorizationException::class);
    $this->categoryService->createCategory(
      new Category(9, 'bob', 'Planted', null),
      $this->createUser('alice')
    );
  }

  public function testNonAdminCannotCreateGlobalCategory(): void
  {
    $this->categoryRepository->method('findByCompositeKey')->willReturn(null);
    $this->categoryRepository->expects($this->never())->method('save');

    $this->expectException(AuthorizationException::class);
    $this->categoryService->createCategory(
      new Category(9, null, 'Global', null),
      $this->createUser('alice')
    );
  }

  public function testCreateCategoryRefusesToOverwriteExistingRow(): void
  {
    // Composite-key collision: (5, 'jdoe') already exists.
    $existing = new Category(5, 'jdoe', 'Existing', '#111111');
    $this->categoryRepository->method('findByCompositeKey')
      ->with(5, 'jdoe')
      ->willReturn($existing);
    $this->categoryRepository->expects($this->never())->method('save');

    $this->expectException(\DomainException::class);
    $this->categoryService->createCategory(
      new Category(5, 'jdoe', 'Renamed', '#FF0000'),
      $this->createUser('jdoe')
    );
  }

  public function testAdminCannotOverwriteGlobalCategoryViaCreate(): void
  {
    $this->categoryRepository->method('findByCompositeKey')
      ->with(6, '')
      ->willReturn(new Category(6, null, 'Holidays', null));
    $this->categoryRepository->expects($this->never())->method('save');

    $this->expectException(\DomainException::class);
    $this->categoryService->createCategory(
      new Category(6, null, 'Renamed', null),
      $this->createUser('admin', true)
    );
  }

  public function testAdminCanCreateGlobalCategory(): void
  {
    $category = new Category(9, null, 'Global', null);
    $this->categoryRepository->method('findByCompositeKey')->willReturn(null);

    $this->categoryRepository->expects($this->once())
      ->method('save')
      ->with($category);

    $this->categoryService->createCategory($category, $this->createUser('admin', true));
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
      ->method('findByCompositeKey')
      ->with(5, 'jdoe')
      ->willReturn($category);

    $this->categoryRepository->expects($this->once())
      ->method('deleteByCompositeKey')
      ->with(5, 'jdoe');

    $this->categoryService->deleteCategory(5, 'jdoe', $actor);
  }

  public function testNonOwnerCannotDeleteCategory(): void
  {
    $category = new Category(5, 'jdoe', 'My Cat', '#FF0000');
    $actor = $this->createUser('other');

    $this->categoryRepository->expects($this->once())
      ->method('findByCompositeKey')
      ->with(5, 'jdoe')
      ->willReturn($category);

    $this->categoryRepository->expects($this->never())
      ->method('deleteByCompositeKey');

    $this->expectException(AuthorizationException::class);
    $this->categoryService->deleteCategory(5, 'jdoe', $actor);
  }

  public function testDeleteNonExistentCategoryThrows(): void
  {
    $actor = $this->createUser('jdoe');

    $this->categoryRepository->expects($this->once())
      ->method('findByCompositeKey')
      ->with(999, '')
      ->willReturn(null);

    $this->categoryRepository->expects($this->never())
      ->method('deleteByCompositeKey');

    $this->expectException(\DomainException::class);
    $this->categoryService->deleteCategory(999, null, $actor);
  }

  // ── Epic 23: tags ───────────────────────────────────────────────

  public function testGetAllTagsDelegatesToTheRepository(): void
  {
    $tag = new Category(3, null, 'outdoors', null, true, isTag: true);
    $this->categoryRepository->expects($this->once())
      ->method('findAllTags')
      ->willReturn([$tag]);

    $this->assertSame([$tag], $this->categoryService->getAllTags());
  }

  public function testMatchOrCreateTagReturnsTheExistingTag(): void
  {
    $existing = new Category(3, null, 'outdoors', null, true, isTag: true);
    $this->categoryRepository->method('findTagByName')->with('outdoors')->willReturn($existing);
    $this->categoryRepository->expects($this->never())->method('save');

    $this->assertSame($existing, $this->categoryService->matchOrCreateTag('outdoors', $this->createUser('jdoe')));
  }

  public function testMatchOrCreateTagCreatesAGlobalTagWhenMissing(): void
  {
    $this->categoryRepository->method('findTagByName')->willReturn(null);
    $this->categoryRepository->method('nextId')->willReturn(7);
    $this->categoryRepository->expects($this->once())
      ->method('save')
      ->with($this->callback(
        static fn (Category $tag): bool => $tag->isTag() && $tag->isGlobal() && $tag->name() === 'music' && $tag->id() === 7
      ));

    $created = $this->categoryService->matchOrCreateTag('music', $this->createUser('jdoe'));

    $this->assertTrue($created->isTag());
    $this->assertSame(7, $created->id());
  }
}
