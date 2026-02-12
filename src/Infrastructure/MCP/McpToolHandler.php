<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\MCP;

use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Application\Service\SearchService;
use WebCalendar\Core\Application\Service\UserService;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\Entity\Event;

/**
 * Handler for MCP (Model Context Protocol) tool requests.
 */
final readonly class McpToolHandler
{
    public function __construct(
        private EventService $eventService,
        private SearchService $searchService,
        private UserService $userService
    ) {
    }

    /**
     * Handles an MCP tool request.
     * 
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function handle(string $tool, array $params, User $user): array
    {
        return match ($tool) {
            'list_events' => $this->handleListEvents($params, $user),
            'get_user_info' => $this->handleGetUserInfo($user),
            'search_events' => $this->handleSearchEvents($params, $user),
            'add_event' => $this->handleAddEvent($params, $user),
            default => throw new \InvalidArgumentException('Unknown tool: ' . $tool)
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleListEvents(array $params, User $user): array
    {
        $startStr = is_string($params['start_date'] ?? null) ? $params['start_date'] : (string)date('Ymd');
        $endStr = is_string($params['end_date'] ?? null) ? $params['end_date'] : $startStr;

        $range = new DateRange(
            new \DateTimeImmutable($startStr),
            new \DateTimeImmutable($endStr)
        );

        $events = $this->eventService->getEventsInDateRange($range, $user);
        
        $result = [];
        foreach ($events as $event) {
            $result[] = [
                'id' => $event->id()->value(),
                'name' => $event->name(),
                'start' => $event->start()->format(\DateTimeInterface::ATOM),
                'location' => $event->location()
            ];
        }

        return ['events' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleGetUserInfo(User $user): array
    {
        // Actually we might want to reload user from service to get latest preferences
        $currentUser = $this->userService->getUserByLogin($user->login());
        $targetUser = $currentUser ?? $user;

        return [
            'login' => $targetUser->login(),
            'name' => $targetUser->fullName(),
            'email' => $targetUser->email(),
            'isAdmin' => $targetUser->isAdmin()
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleSearchEvents(array $params, User $user): array
    {
        $keyword = is_string($params['keyword'] ?? null) ? $params['keyword'] : '';
        $results = $this->searchService->search($keyword, null, $user);

        $events = [];
        foreach ($results->all() as $event) {
            $events[] = [
                'id' => $event->id()->value(),
                'name' => $event->name(),
                'start' => $event->start()->format(\DateTimeInterface::ATOM)
            ];
        }

        return ['results' => $events];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleAddEvent(array $params, User $user): array
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $dateStr = is_string($params['date'] ?? null) ? $params['date'] : (string)date('Ymd');
        $description = is_string($params['description'] ?? null) ? $params['description'] : '';
        $location = is_string($params['location'] ?? null) ? $params['location'] : '';
        $duration = is_numeric($params['duration'] ?? null) ? (int)$params['duration'] : 60;

        $event = new Event(
            id: new EventId(0),
            uid: bin2hex(random_bytes(16)),
            name: $name,
            description: $description,
            location: $location,
            start: new \DateTimeImmutable($dateStr),
            duration: $duration,
            createdBy: $user->login(),
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $this->eventService->createEvent($event);

        return ['success' => true, 'event_id' => $event->id()->value()];
    }
}
