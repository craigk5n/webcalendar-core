<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Application\DTO\ImportResult;
use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\CategoryRepositoryInterface;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Infrastructure\ICal\EventMapper;
use Icalendar\Parser\Parser;
use Icalendar\Component\VEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for importing calendar data from external formats.
 */
final readonly class ImportService
{
    private Parser $parser;
    private LoggerInterface $logger;

    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private EventMapper $eventMapper,
        private ?CategoryRepositoryInterface $categoryRepository = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->parser = new Parser(Parser::LENIENT);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Imports events from an iCalendar (.ics) string.
     *
     * @param string $icsContent The ICS file content.
     * @param User $user The user importing the events.
     */
    public function importIcal(string $icsContent, User $user): ImportResult
    {
        $this->logger->info('Starting iCal import', ['user' => $user->login(), 'content_length' => strlen($icsContent)]);

        try {
            $vcalendar = $this->parser->parse($icsContent);
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse iCal content', ['error' => $e->getMessage()]);
            throw $e;
        }

        $count = 0;
        $skipped = 0;
        foreach ($vcalendar->getComponents() as $component) {
            if ($component instanceof VEvent) {
                try {
                    $event = $this->eventMapper->fromVEvent($component, $user->login());

                    // Update detection: check if an event with the same UID already exists
                    $existingEvent = $this->eventRepository->findByUid($event->uid());

                    if ($existingEvent !== null) {
                        $this->logger->debug('Skipping existing event', ['uid' => $event->uid()]);
                        $skipped++;
                        continue;
                    }

                    $this->eventRepository->create($event);
                    $count++;

                    // Handle categories if present in the component and repo is available
                    if ($this->categoryRepository !== null) {
                        $categories = $component->getProperty('CATEGORIES');
                        if ($categories !== null) {
                            $catNames = explode(',', $categories->getValue()->getRawValue());
                            foreach ($catNames as $catName) {
                                $catName = trim($catName);
                                if ($catName === '') continue;

                                $category = $this->categoryRepository->findByName($catName, $user->login());
                                if ($category === null) {
                                    $category = new Category(0, $user->login(), $catName, null);
                                    $this->categoryRepository->create($category);
                                    // Need to find it again to get the ID if created
                                    $category = $this->categoryRepository->findByName($catName, $user->login());
                                }

                                if ($category !== null) {
                                    $this->categoryRepository->assignToEvent($event->id(), $user->login(), [$category->id()]);
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to import VEVENT', ['error' => $e->getMessage(), 'uid' => $component->getProperty('UID')?->getValue()->getRawValue()]);
                }
            }
        }

        $this->logger->info('iCal import completed', ['imported_count' => $count, 'skipped_count' => $skipped]);

        return new ImportResult($count, $skipped);
    }
}
