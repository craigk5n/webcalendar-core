<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\SearchService;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\EventCollection;
use WebCalendar\Core\Domain\ValueObject\EventScope;
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

        $scope = EventScope::forUser($user);

        $this->eventRepository->expects($this->once())
            ->method('search')
            ->with($keyword, $this->identicalTo($scope), null)
            ->willReturn($events);

        $result = $this->searchService->search($keyword, $scope);
        
        $this->assertSame($events, $result);
    }
}
