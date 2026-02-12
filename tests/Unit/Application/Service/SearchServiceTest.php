<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\SearchService;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\EventCollection;
use WebCalendar\Core\Domain\ValueObject\DateRange;

final class SearchServiceTest extends TestCase
{
    /** @var EventRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventRepository;
    private SearchService $searchService;

    protected function setUp(): void
    {
        $this->eventRepository = $this->createMock(EventRepositoryInterface::class);
        $this->searchService = new SearchService($this->eventRepository);
    }

    public function testSearchByKeyword(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        $keyword = 'Meeting';
        $events = new EventCollection([]);

        // The repository doesn't have a specific search method yet, 
        // we might need to add one or use findByDateRange and filter.
        // PRD 17.5 suggests an API endpoint for search.
        // Let's assume we'll add a search method to the repository interface.
        
        $this->eventRepository->expects($this->once())
            ->method('search')
            ->with($keyword, null, $user)
            ->willReturn($events);

        $result = $this->searchService->search($keyword, null, $user);
        
        $this->assertSame($events, $result);
    }
}
