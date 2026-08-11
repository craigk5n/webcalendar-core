<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\ExportService;
use WebCalendar\Core\Infrastructure\ICal\EventMapper;
use WebCalendar\Core\Domain\ValueObject\EventCollection;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;

final class ExportServiceTest extends TestCase
{
    private ExportService $exportService;

    protected function setUp(): void
    {
        $this->exportService = new ExportService(new EventMapper());
    }

    public function testExportIcal(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $event = new Event(
            id: new EventId(1),
            uid: 'test-uid',
            name: 'Test Event',
            description: 'This is a test',
            location: 'Online',
            start: $start,
            duration: 60,
            createdBy: 'creator-login',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $events = new EventCollection([$event]);
        $ics = $this->exportService->exportIcal($events);

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('SUMMARY:Test Event', $ics);
        $this->assertStringContainsString('UID:test-uid', $ics);
        $this->assertStringContainsString('END:VEVENT', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
    }

    // ---- Tags (X-WEBCAL-TAGS) -----------------------------------------------

    private function event(string $uid = 'tag-uid'): Event
    {
        return new Event(
            id: new EventId(1),
            uid: $uid,
            name: 'Tagged Event',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-02-11 10:00:00'),
            duration: 60,
            createdBy: 'creator-login',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );
    }

    /** The first VEVENT of a written calendar, narrowed for analysis. */
    private function parseFirstEvent(string $ics): \Icalendar\Component\VEvent
    {
        $vevent = (new \Icalendar\Parser\Parser())->parse($ics)->getComponents('VEVENT')[0];
        $this->assertInstanceOf(\Icalendar\Component\VEvent::class, $vevent);
        return $vevent;
    }

    /**
     * @param list<string> $categories
     * @param list<string> $tags
     */
    private function exportWith(array $categories, array $tags): string
    {
        return $this->exportService->exportIcal(
            new EventCollection([$this->event()]),
            [1 => $categories],
            [1 => $tags]
        );
    }

    public function testTagsGoOutInCategoriesAsWellAsTheTagProperty(): void
    {
        $ics = $this->exportWith(['Meetings'], ['outdoors']);

        // In CATEGORIES so other calendars still show every label...
        $this->assertStringContainsString('CATEGORIES:Meetings,outdoors', $ics);
        // ...and named again so an import can tell which were tags.
        $this->assertStringContainsString('X-WEBCAL-TAGS:outdoors', $ics);
    }

    public function testNoTagPropertyWhenTheEventHasNoTags(): void
    {
        $ics = $this->exportWith(['Meetings'], []);

        $this->assertStringContainsString('CATEGORIES:Meetings', $ics);
        $this->assertStringNotContainsString('X-WEBCAL-TAGS', $ics);
    }

    public function testAnEventWithOnlyTagsStillGetsACategoriesLine(): void
    {
        $ics = $this->exportWith([], ['outdoors', 'family']);

        $this->assertStringContainsString('CATEGORIES:outdoors,family', $ics);

        // Asserted through the parser, not the raw line: the writer escapes
        // the separators it is handed, so the bytes on disk read
        // "outdoors\,family". What matters is what a reader gets back.
        $this->assertSame(['outdoors', 'family'], (new EventMapper())->extractTagNames($this->parseFirstEvent($ics)));
    }

    /**
     * The property only means anything if it survives being written and read
     * back, including names carrying the separator. The writer escapes what
     * the mapper already escaped and the parser undoes one level, so this is
     * the only honest way to check the encoding — asserting a hand-written
     * escaped string would just assert a guess at it.
     */
    public function testTagNamesRoundTripThroughAWrittenFile(): void
    {
        $mapper = new EventMapper();
        $ics = $this->exportWith(['Meetings'], ['Food,Drink', 'plain']);

        $vevent = $this->parseFirstEvent($ics);

        $this->assertSame(['Meetings', 'Food,Drink', 'plain'], $mapper->extractCategoryNames($vevent));
        $this->assertSame(['Food,Drink', 'plain'], $mapper->extractTagNames($vevent));
    }

    public function testExtractingTagsFromAFileWithoutThePropertyYieldsNothing(): void
    {
        $ics = $this->exportWith(['Meetings'], []);
        $this->assertSame([], (new EventMapper())->extractTagNames($this->parseFirstEvent($ics)));
    }
}
