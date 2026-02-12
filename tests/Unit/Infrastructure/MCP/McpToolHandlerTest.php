<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Infrastructure\MCP;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Infrastructure\MCP\McpToolHandler;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Application\Service\SearchService;
use WebCalendar\Core\Application\Service\UserService;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\EventCollection;

final class McpToolHandlerTest extends TestCase
{
    /** @var EventRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventRepository;
    /** @var UserRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $userRepository;
    private McpToolHandler $mcpToolHandler;

    protected function setUp(): void
    {
        $this->eventRepository = $this->createMock(EventRepositoryInterface::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        
        $eventService = new EventService($this->eventRepository);
        $searchService = new SearchService($this->eventRepository);
        $userService = new UserService($this->userRepository);

        $this->mcpToolHandler = new McpToolHandler(
            $eventService,
            $searchService,
            $userService
        );
    }

    public function testListEvents(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'jdoe@example.com', false, true);
        
        $this->eventRepository->expects($this->once())
            ->method('findByDateRange')
            ->willReturn([]);

        $result = $this->mcpToolHandler->handle('list_events', [
            'start_date' => '20260201',
            'end_date' => '20260228'
        ], $user);

        $this->assertIsArray($result);
    }

    public function testSearchEvents(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'jdoe@example.com', false, true);
        
        $this->eventRepository->expects($this->once())
            ->method('search')
            ->willReturn(new EventCollection([]));

        $result = $this->mcpToolHandler->handle('search_events', [
            'keyword' => 'Meeting'
        ], $user);

        $this->assertIsArray($result);
    }
}
