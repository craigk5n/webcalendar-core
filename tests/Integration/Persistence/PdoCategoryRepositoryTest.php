<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Infrastructure\Persistence\PdoCategoryRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

final class PdoCategoryRepositoryTest extends RepositoryTestCase
{
    private PdoCategoryRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PdoCategoryRepository($this->pdo);

        // Clean category-related tables
        $this->pdo->exec('DELETE FROM webcal_entry_categories');
        $this->pdo->exec('DELETE FROM webcal_categories');
    }

    public function testSaveAndFindById(): void
    {
        $category = new Category(1, null, 'Meeting', '#0073aa');
        $this->repository->save($category);

        $found = $this->repository->findById(1);
        $this->assertNotNull($found);
        $this->assertSame('Meeting', $found->name());
        $this->assertSame('#0073aa', $found->color());
    }

    public function testFindByNameIsCaseInsensitive(): void
    {
        $category = new Category(1, null, 'Holiday', '#dc3232');
        $this->repository->save($category);

        $found = $this->repository->findByName('holiday');
        $this->assertNotNull($found);
        $this->assertSame('Holiday', $found->name());

        $found = $this->repository->findByName('HOLIDAY');
        $this->assertNotNull($found);
        $this->assertSame('Holiday', $found->name());

        $found = $this->repository->findByName('hoLiDay');
        $this->assertNotNull($found);
        $this->assertSame('Holiday', $found->name());
    }

    public function testFindByNameReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repository->findByName('nonexistent'));
    }

    public function testGetEventCountReturnsZeroForNoEvents(): void
    {
        $category = new Category(1, null, 'Empty', '#000');
        $this->repository->save($category);

        $this->assertSame(0, $this->repository->getEventCount(1));
    }

    public function testGetEventCountReturnsCorrectCount(): void
    {
        $category = new Category(1, null, 'Work', '#000');
        $this->repository->save($category);

        // Insert test entries
        $this->insertEntry(100);
        $this->insertEntry(101);
        $this->insertEntry(102);

        // Assign events to category
        $this->repository->assignToEvent(new EventId(100), 'admin', [1]);
        $this->repository->assignToEvent(new EventId(101), 'admin', [1]);
        $this->repository->assignToEvent(new EventId(102), 'admin', [1]);

        $this->assertSame(3, $this->repository->getEventCount(1));
    }

    public function testGetEventCountCountsDistinctEvents(): void
    {
        $category = new Category(1, null, 'Shared', '#000');
        $this->repository->save($category);

        $this->insertEntry(100);

        // Same event assigned by two different users
        $this->repository->assignToEvent(new EventId(100), 'admin', [1]);
        $this->repository->assignToEvent(new EventId(100), 'jdoe', [1]);

        // Should count the event only once
        $this->assertSame(1, $this->repository->getEventCount(1));
    }

    public function testReassignEventsMovesEventsToTarget(): void
    {
        $source = new Category(1, null, 'Source', '#000');
        $target = new Category(2, null, 'Target', '#fff');
        $this->repository->save($source);
        $this->repository->save($target);

        $this->insertEntry(100);
        $this->insertEntry(101);

        $this->repository->assignToEvent(new EventId(100), 'admin', [1]);
        $this->repository->assignToEvent(new EventId(101), 'admin', [1]);

        $this->repository->reassignEvents(1, 2, 'admin');

        $this->assertSame(0, $this->repository->getEventCount(1));
        $this->assertSame(2, $this->repository->getEventCount(2));
    }

    public function testReassignEventsDeduplicates(): void
    {
        $source = new Category(1, null, 'Source', '#000');
        $target = new Category(2, null, 'Target', '#fff');
        $this->repository->save($source);
        $this->repository->save($target);

        $this->insertEntry(100);

        // Event 100 is in both categories
        $this->repository->assignToEvent(new EventId(100), 'admin', [1]);
        $this->repository->assignToEvent(new EventId(100), 'jdoe', [2]);

        $this->repository->reassignEvents(1, 2, 'admin');

        // Source should be empty
        $this->assertSame(0, $this->repository->getEventCount(1));
        // Target should still have event 100 (no duplicates)
        $this->assertSame(1, $this->repository->getEventCount(2));
    }

    public function testDeleteRemovesCategory(): void
    {
        $category = new Category(1, null, 'ToDelete', '#000');
        $this->repository->save($category);
        $this->assertNotNull($this->repository->findById(1));

        $this->repository->delete(1);
        $this->assertNull($this->repository->findById(1));
    }

    public function testFindAllGlobal(): void
    {
        $this->repository->save(new Category(1, null, 'Global1', '#000'));
        $this->repository->save(new Category(2, null, 'Global2', '#fff'));
        $this->repository->save(new Category(3, 'jdoe', 'Personal', '#ccc'));

        $globals = $this->repository->findAllGlobal();
        $this->assertCount(2, $globals);
    }

    public function testFindByOwner(): void
    {
        $this->repository->save(new Category(1, null, 'Global', '#000'));
        $this->repository->save(new Category(2, 'jdoe', 'JDoe Cat', '#fff'));
        $this->repository->save(new Category(3, 'jdoe', 'JDoe Cat 2', '#ccc'));

        $owned = $this->repository->findByOwner('jdoe');
        $this->assertCount(2, $owned);
    }

    public function testUpdateExistingCategory(): void
    {
        $category = new Category(1, null, 'Original', '#000');
        $this->repository->save($category);

        $updated = new Category(1, null, 'Renamed', '#fff');
        $this->repository->save($updated);

        $found = $this->repository->findById(1);
        $this->assertNotNull($found);
        $this->assertSame('Renamed', $found->name());
        $this->assertSame('#fff', $found->color());
    }

    private function insertEntry(int $calId): void
    {
        $this->pdo->exec(
            "INSERT INTO webcal_entry (cal_id, cal_name, cal_date, cal_time, cal_duration, cal_create_by, cal_type, cal_access)
             VALUES ($calId, 'Test Event $calId', 20260214, 100000, 60, 'admin', 'E', 'P')"
        );
    }
}
