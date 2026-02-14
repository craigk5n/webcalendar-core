<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\CategoryRepositoryInterface;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Infrastructure\ICal\EventMapper;
use Icalendar\Parser\Parser;
use Icalendar\Component\VEvent;

/**
 * Service for importing calendar data from external formats.
 */
final readonly class ImportService
{
    private Parser $parser;

    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private EventMapper $eventMapper,
        private ?CategoryRepositoryInterface $categoryRepository = null,
    ) {
        $this->parser = new Parser(Parser::LENIENT);
    }

    /**
     * Imports events from an iCalendar (.ics) string.
     *
     * @param string $icsContent The ICS file content.
     * @param User $user The user importing the events.
     */
    public function importIcal(string $icsContent, User $user): void
    {
        $vcalendar = $this->parser->parse($icsContent);

        foreach ($vcalendar->getComponents() as $component) {
            if ($component instanceof VEvent) {
                $event = $this->eventMapper->fromVEvent($component, $user->login());

                // Update detection: check if an event with the same UID already exists
                $existingEvent = $this->eventRepository->findByUid($event->uid());

                if ($existingEvent !== null) {
                    continue;
                }

                $this->eventRepository->save($event);

                // Handle categories from iCal CATEGORIES property
                if ($this->categoryRepository !== null) {
                    $categoryNames = $this->eventMapper->extractCategoryNames($component);
                    if (!empty($categoryNames)) {
                        $savedEvent = $this->eventRepository->findByUid($event->uid());
                        if ($savedEvent !== null) {
                            $categoryIds = $this->resolveCategories($categoryNames);
                            $this->categoryRepository->assignToEvent(
                                $savedEvent->id(),
                                $user->login(),
                                $categoryIds
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * Resolves category names to IDs, auto-creating global categories as needed.
     *
     * @param string[] $names
     * @return int[]
     */
    private function resolveCategories(array $names): array
    {
        assert($this->categoryRepository !== null);

        $ids = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $existing = $this->categoryRepository->findByName($name);
            if ($existing !== null) {
                $ids[] = $existing->id();
            } else {
                $newId = $this->categoryRepository->nextId();
                $category = new Category(
                    id: $newId,
                    owner: null,
                    name: $name,
                    color: null,
                );
                $this->categoryRepository->save($category);
                $ids[] = $newId;
            }
        }
        return $ids;
    }
}
