<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use WebCalendar\Core\Application\Service\ImportLimitException;
use WebCalendar\Core\Application\Service\ImportService;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Infrastructure\ICal\EventMapper;
use WebCalendar\Core\Infrastructure\Persistence\PdoCategoryRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoEventRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

/**
 * UIDs are per importer: two users importing one feed each get, update and
 * keep their own copy. CATEGORIES names are capped before anything is
 * written, since each can create a persistent category or global tag.
 */
final class ImportUidScopeAndLimitsTest extends RepositoryTestCase
{
    private PdoEventRepository $events;
    private ImportService $import;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DELETE FROM webcal_entry_categories');
        $this->pdo->exec('DELETE FROM webcal_categories');
        $this->events = new PdoEventRepository($this->pdo);
        $this->import = new ImportService(
            $this->events,
            new EventMapper(),
            new PdoCategoryRepository($this->pdo),
            maxCategoriesPerEvent: 5,
            maxCategoriesPerImport: 8,
        );
        $this->alice = new User('alice', 'A', 'A', 'alice@example.test', false, true);
        $this->bob = new User('bob', 'B', 'B', 'bob@example.test', false, true);
    }

    private function feed(string $summary, string $categories = ''): string
    {
        $cats = $categories === '' ? '' : "CATEGORIES:$categories\r\n";
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:holiday-2027-01-01@public-feed.test\r\n"
            . "DTSTART:20270101T090000Z\r\nDURATION:PT1H\r\nSUMMARY:$summary\r\n$cats"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /** @return array<string, string> login => name */
    private function copies(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT cal_create_by, cal_name FROM webcal_entry
              WHERE cal_uid = 'holiday-2027-01-01@public-feed.test' ORDER BY cal_create_by"
        );
        $stmt->execute();
        /** @var array<string, string> */
        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    private function rowCount(string $table): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM $table");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    // ---- Composite-key and cross-scope isolation regression coverage ----

    public function testTwoUsersImportingOneUidEachGetTheirOwnCopy(): void
    {
        $this->assertSame(1, $this->import->importIcal($this->feed('New Year'), $this->bob)->importedCount);

        $result = $this->import->importIcal($this->feed('New Year'), $this->alice);

        $this->assertSame(1, $result->importedCount);
        $this->assertSame([], $result->warnings);
        $this->assertSame(['alice' => 'New Year', 'bob' => 'New Year'], $this->copies());
    }

    public function testAnUpdateReachesOnlyTheImportersCopy(): void
    {
        $this->import->importIcal($this->feed('New Year'), $this->bob);
        $this->import->importIcal($this->feed('New Year'), $this->alice);

        $result = $this->import->importIcal($this->feed('New Year (moved)'), $this->alice, true);

        $this->assertSame(1, $result->updatedCount);
        $this->assertSame(['alice' => 'New Year (moved)', 'bob' => 'New Year'], $this->copies());
    }

    public function testReimportWithoutUpdateSkipsOnlyTheImportersOwnCopy(): void
    {
        $this->import->importIcal($this->feed('New Year'), $this->alice);

        $result = $this->import->importIcal($this->feed('New Year'), $this->alice);

        $this->assertSame(0, $result->importedCount);
        $this->assertSame(1, $result->skippedCount);
        $this->assertCount(1, $this->copies());
    }

    // ---- category limits -------------------------------------------------

    public function testTooManyCategoriesOnOneEventIsRejectedBeforeAnyWrite(): void
    {
        try {
            $this->import->importIcal($this->feed('x', 'a,b,c,d,e,f'), $this->alice);
            $this->fail('expected ImportLimitException');
        } catch (ImportLimitException $e) {
            $this->assertStringContainsString('on one event', $e->getMessage());
        }

        $this->assertSame(0, $this->rowCount('webcal_categories'));
        $this->assertSame(0, $this->rowCount('webcal_entry'));
    }

    public function testTooManyCategoriesAcrossTheImportIsRejected(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n";
        foreach ([1, 2] as $i) {
            $ics .= "BEGIN:VEVENT\r\nUID:e$i@test\r\nDTSTART:20270101T090000Z\r\nDURATION:PT1H\r\n"
                . "SUMMARY:e$i\r\nCATEGORIES:a$i,b$i,c$i,d$i,e$i\r\nEND:VEVENT\r\n";
        }
        $ics .= "END:VCALENDAR\r\n";

        $this->expectException(ImportLimitException::class);
        $this->expectExceptionMessage('in total');
        $this->import->importIcal($ics, $this->alice);
    }

    public function testCategoriesWithinTheLimitsImport(): void
    {
        $result = $this->import->importIcal($this->feed('x', 'a,b,c,d,e'), $this->alice);

        $this->assertSame(1, $result->importedCount);
        $this->assertSame(5, $this->rowCount('webcal_categories'));
    }
}
