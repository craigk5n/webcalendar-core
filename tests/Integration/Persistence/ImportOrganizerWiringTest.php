<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use WebCalendar\Core\Application\Service\ImportService;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Infrastructure\ICal\EventMapper;
use WebCalendar\Core\Infrastructure\Persistence\PdoEventRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoOrganizerRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

/**
 * Epic 22 follow-up — the iCal import path match-or-creates ORGANIZER
 * entities and links events to them. Real repos over SQLite end to end.
 */
final class ImportOrganizerWiringTest extends RepositoryTestCase
{
    private ImportService $service;
    private PdoEventRepository $events;
    private PdoOrganizerRepository $organizers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new PdoEventRepository($this->pdo);
        $this->organizers = new PdoOrganizerRepository($this->pdo);
        $this->service = new ImportService(
            $this->events,
            new EventMapper(),
            null,
            10485760,
            1000,
            null,
            $this->organizers,
        );
    }

    private function ics(string $uid, string $summary, string $organizerLine): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//WC-FIX//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:$uid\r\nSUMMARY:$summary\r\n"
            . "DTSTART:20260910T100000\r\nDURATION:PT1H\r\n"
            . $organizerLine
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function user(): User
    {
        return new User('admin', 'Ad', 'Min', 'admin@example.com', true, true);
    }

    public function testOrganizerIsCreatedAndLinkedOnImport(): void
    {
        $ics = $this->ics('wc-org-1', 'Organized Event', "ORGANIZER;CN=Alice Organizer:mailto:alice@example.com\r\n");

        $result = $this->service->importIcal($ics, $this->user());

        $this->assertSame(1, $result->importedCount);
        $organizer = $this->organizers->findByName('Alice Organizer');
        $this->assertNotNull($organizer);
        $this->assertSame('alice@example.com', $organizer->email());

        $event = $this->events->findByUid('wc-org-1');
        $this->assertNotNull($event);
        $this->assertSame($organizer->id()->value(), $event->organizerId()?->value());
    }

    public function testRepeatedImportsReuseTheOrganizer(): void
    {
        $line = "ORGANIZER;CN=Alice Organizer:mailto:alice@example.com\r\n";
        $this->service->importIcal($this->ics('wc-org-1', 'First', $line), $this->user());
        $this->service->importIcal($this->ics('wc-org-2', 'Second', $line), $this->user());

        $this->assertCount(1, $this->organizers->findAll(), 'no duplicate organizers');
    }

    public function testEventWithoutOrganizerImportsUntouched(): void
    {
        $result = $this->service->importIcal($this->ics('wc-org-3', 'Plain', ''), $this->user());

        $this->assertSame(1, $result->importedCount);
        $event = $this->events->findByUid('wc-org-3');
        $this->assertNotNull($event);
        $this->assertNull($event->organizerId());
        $this->assertSame([], $this->organizers->findAll());
    }
}
