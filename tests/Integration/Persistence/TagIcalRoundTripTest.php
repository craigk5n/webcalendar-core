<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use WebCalendar\Core\Application\Service\ExportService;
use WebCalendar\Core\Application\Service\ImportService;
use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventCollection;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Infrastructure\ICal\EventMapper;
use WebCalendar\Core\Infrastructure\Persistence\PdoCategoryRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoEventRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

/**
 * Exporting a calendar and importing it into another instance must not
 * turn tags into categories.
 *
 * Real repositories over SQLite, going all the way out through the writer
 * and back in through the parser. Everything below the file — the shared
 * webcal_categories table, cat_is_tag, the delete-then-insert assignment —
 * is exercised as it would be on a live site.
 */
final class TagIcalRoundTripTest extends RepositoryTestCase
{
    private PdoEventRepository $events;
    private PdoCategoryRepository $categories;
    private ExportService $export;
    private ImportService $import;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DELETE FROM webcal_entry_categories');
        $this->pdo->exec('DELETE FROM webcal_categories');

        $this->events = new PdoEventRepository($this->pdo);
        $this->categories = new PdoCategoryRepository($this->pdo);
        $this->export = new ExportService(new EventMapper());
        $this->import = new ImportService(
            $this->events,
            new EventMapper(),
            $this->categories,
        );
    }

    private function actor(): User
    {
        return new User('importer', 'Im', 'Porter', 'im@example.com', false, true);
    }

    /**
     * The source instance: one event with a category and two tags.
     */
    private function seedSourceCalendar(): string
    {
        $event = new Event(
            id: new EventId(0),
            uid: 'round-trip-1',
            name: 'Yoga in the Park',
            description: 'Bring a mat.',
            location: 'Community Hall',
            start: new \DateTimeImmutable('2026-09-10 10:00:00'),
            duration: 60,
            createdBy: 'importer',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );
        $this->events->save($event);
        $saved = $this->events->findByUid('round-trip-1');
        self::assertNotNull($saved);

        $this->categories->create(new Category(1, '', 'Meetings', '#c0392b'));
        $this->categories->create(new Category(2, null, 'outdoors', null, true, true));
        $this->categories->create(new Category(3, null, 'Food,Drink', null, true, true));
        $this->categories->assignToEvent($saved->id(), 'importer', [1, 2, 3]);

        $id = $saved->id()->value();
        return $this->export->exportIcal(
            new EventCollection([$saved]),
            [$id => ['Meetings']],
            [$id => ['outdoors', 'Food,Drink']],
        );
    }

    /**
     * Wipe everything an instance holds, keeping the schema — "a different
     * instance" for the purposes of the import.
     */
    private function emptyTheInstance(): void
    {
        $this->pdo->exec('DELETE FROM webcal_entry_categories');
        $this->pdo->exec('DELETE FROM webcal_categories');
        $this->pdo->exec('DELETE FROM webcal_entry');
    }

    /** @return array<string, string> name => cat_is_tag */
    private function storedLabels(): array
    {
        $stmt = $this->pdo->query('SELECT cat_name, cat_is_tag FROM webcal_categories ORDER BY cat_name');
        self::assertNotFalse($stmt);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['cat_name']] = (string) ($row['cat_is_tag'] ?? 'N');
        }
        return $out;
    }

    public function testTagsSurviveExportAndImportIntoAnotherInstance(): void
    {
        $ics = $this->seedSourceCalendar();
        $this->emptyTheInstance();

        $this->import->importIcal($ics, $this->actor());

        $this->assertSame(
            ['Food,Drink' => 'Y', 'Meetings' => 'N', 'outdoors' => 'Y'],
            $this->storedLabels(),
            'tags must arrive as tags, and the comma in a name must survive'
        );
    }

    public function testTheEventKeepsBothItsCategoryAndItsTags(): void
    {
        $ics = $this->seedSourceCalendar();
        $this->emptyTheInstance();

        $this->import->importIcal($ics, $this->actor());

        $imported = $this->events->findByUid('round-trip-1');
        $this->assertNotNull($imported);

        $tags = $this->categories->getTagsForEvent($imported->id(), 'importer');
        $this->assertSame(
            ['outdoors', 'Food,Drink'],
            array_map(static fn (Category $c): string => $c->name(), $tags)
        );

        // getForEvent returns both kinds; the category has to still be there.
        $all = $this->categories->getForEvent($imported->id(), 'importer');
        $names = array_map(static fn (Category $c): string => $c->name(), $all);
        $this->assertContains('Meetings', $names);
        $this->assertCount(3, $names, 'one category and two tags');
    }

    /**
     * The regression that motivated this: a file whose labels are all
     * categories must not be able to take over a tag the target already has.
     */
    public function testACategoryImportDoesNotHijackAnExistingTagOfTheSameName(): void
    {
        $ics = $this->seedSourceCalendar();
        $this->emptyTheInstance();

        // The target instance already has "Meetings" as a tag.
        $this->categories->create(new Category(50, null, 'Meetings', null, true, true));

        $this->import->importIcal($ics, $this->actor());

        $labels = $this->storedLabels();
        $this->assertSame('N', $labels['Meetings'] ?? null, 'a category row must exist');

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM webcal_categories WHERE cat_name = 'Meetings'");
        self::assertNotFalse($stmt);
        $this->assertSame(2, (int) $stmt->fetchColumn(), 'the tag and the category coexist');
    }

    /**
     * Regression: two categories new to this instance must both be created.
     *
     * They were both built with id 0, and save() writes the id it is given
     * against the composite key (cat_id, cat_owner) — so the second became
     * an UPDATE of the first, and the file's first category was overwritten
     * rather than added. Only visible against a real repository, which is
     * why the mocked unit tests never saw it.
     */
    public function testTwoNewCategoriesAreBothCreated(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Another Calendar//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:two-cats\r\nSUMMARY:Two Categories\r\n"
            . "DTSTART:20260910T100000\r\nDURATION:PT1H\r\n"
            . "CATEGORIES:Alpha,Beta\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";

        $this->import->importIcal($ics, $this->actor());

        $this->assertSame(['Alpha' => 'N', 'Beta' => 'N'], $this->storedLabels());

        $imported = $this->events->findByUid('two-cats');
        $this->assertNotNull($imported);
        $names = array_map(
            static fn (Category $c): string => $c->name(),
            $this->categories->getForEvent($imported->id(), 'importer')
        );
        $this->assertSame(['Alpha', 'Beta'], $names);
    }

    /**
     * A file from any other calendar has no X-WEBCAL-TAGS, and everything in
     * it is a category. That has to keep working unchanged.
     */
    public function testAForeignFileWithoutThePropertyImportsEverythingAsCategories(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Another Calendar//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:foreign-1\r\nSUMMARY:Foreign Event\r\n"
            . "DTSTART:20260910T100000\r\nDURATION:PT1H\r\n"
            . "CATEGORIES:Meetings,outdoors\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";

        $this->import->importIcal($ics, $this->actor());

        $this->assertSame(['Meetings' => 'N', 'outdoors' => 'N'], $this->storedLabels());
    }

    /**
     * Re-importing the same file must not accumulate duplicates — the same
     * property that makes a periodic re-import safe.
     */
    public function testReimportingTheSameFileCreatesNothingNew(): void
    {
        $ics = $this->seedSourceCalendar();
        $this->emptyTheInstance();

        $this->import->importIcal($ics, $this->actor());
        $before = $this->storedLabels();

        $this->import->importIcal($ics, $this->actor(), true);

        $this->assertSame($before, $this->storedLabels());
    }
}
