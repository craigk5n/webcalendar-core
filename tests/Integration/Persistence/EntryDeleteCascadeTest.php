<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use PHPUnit\Framework\Attributes\DataProvider;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\Journal;
use WebCalendar\Core\Domain\Entity\Task;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Infrastructure\Persistence\PdoEventRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoJournalRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoTaskRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoUserRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

/**
 * Entry ids are allocated as MAX(cal_id)+1, so deleting the newest entry
 * frees its id for the next one any user creates. Every row keyed by the
 * deleted cal_id must go with it, or the next entry inherits the previous
 * owner's attachments, extras, reminders and ticket orders.
 *
 * Also covers user deletion: rows keyed by the deleted login must go, or a
 * later account reusing that login inherits them.
 */
final class EntryDeleteCascadeTest extends RepositoryTestCase
{
    /** Tables holding per-entry rows keyed by cal_id. */
    private const DEPENDENT_TABLES = [
        'webcal_entry_user',
        'webcal_entry_repeats',
        'webcal_entry_repeats_not',
        'webcal_entry_categories',
        'webcal_entry_ext_user',
        'webcal_site_extras',
        'webcal_reminders',
        'webcal_blob',
        'webcal_import_data',
        'webcal_attendee',
        'webcal_ticket_order',
        'webcal_ticket_type',
    ];

    private PdoEventRepository $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new PdoEventRepository($this->pdo);
        foreach (['alice', 'bob'] as $login) {
            $this->pdo->exec("INSERT INTO webcal_user (cal_login, cal_passwd, cal_is_admin, cal_email)
                              VALUES ('$login', 'x', 'N', '$login@example.com')");
        }
    }

    // ---- entry delete ------------------------------------------------------

    public function testEventDeleteRemovesEveryDependentRow(): void
    {
        $victim = $this->saveEvent('alice', 'victim');
        $other = $this->saveEvent('bob', 'other');
        $this->seedDependents($victim, 'alice', 1);
        $this->seedDependents($other, 'bob', 2);

        $this->events->delete(new EventId($victim));

        foreach (self::DEPENDENT_TABLES as $table) {
            $this->assertSame(0, $this->countFor($table, $victim), "$table rows must go with the entry");
            $this->assertGreaterThan(0, $this->countFor($table, $other), "$table rows of another entry must stay");
        }
    }

    public function testEntryDeleteWithCompositeKeyCollisions(): void
    {
        // Several rows share the deleted cal_id under different logins, and
        // another entry has rows under the same logins.
        $victim = $this->saveEvent('alice', 'victim');
        $other = $this->saveEvent('bob', 'other');
        foreach ([$victim, $other] as $calId) {
            $this->pdo->exec("INSERT INTO webcal_entry_user (cal_id, cal_login, cal_status) VALUES ($calId, 'carol', 'A')");
            foreach (['alice', 'bob'] as $i => $login) {
                $importId = $calId * 10 + $i;
                $this->pdo->exec("INSERT INTO webcal_import_data (cal_import_id, cal_id, cal_login, cal_import_type)
                                  VALUES ($importId, $calId, '$login', 'ical')");
            }
        }

        $this->events->delete(new EventId($victim));

        $this->assertSame(0, $this->countFor('webcal_entry_user', $victim));
        $this->assertSame(0, $this->countFor('webcal_import_data', $victim));
        $this->assertSame(2, $this->countFor('webcal_entry_user', $other), 'bob + carol on the other entry');
        $this->assertSame(2, $this->countFor('webcal_import_data', $other));
    }

    public function testReusedIdDoesNotInheritDeletedEntrysRows(): void
    {
        $victim = $this->saveEvent('alice', 'victim');
        $this->seedDependents($victim, 'alice', 1);
        $this->events->delete(new EventId($victim));

        $reused = $this->saveEvent('bob', 'bobs-new');

        $this->assertSame($victim, $reused, 'MAX(cal_id)+1 reissues the freed id');
        foreach (self::DEPENDENT_TABLES as $table) {
            if ($table === 'webcal_entry_user') {
                continue; // save() adds bob as creator participant
            }
            $this->assertSame(0, $this->countFor($table, $reused), "$table must be empty for the new entry");
        }
    }

    public function testTaskAndJournalDeleteRemoveDependentRows(): void
    {
        $tasks = new PdoTaskRepository($this->pdo);
        $journals = new PdoJournalRepository($this->pdo);
        $start = new \DateTimeImmutable('2026-04-01 10:00:00');

        $tasks->save(new Task(new EventId(0), 't', 'Task', '', '', $start, 0, 'alice', EventType::TASK, AccessLevel::PRIVATE));
        $journals->save(new Journal(new EventId(0), 'j', 'Journal', '', '', $start, 0, 'alice', EventType::JOURNAL, AccessLevel::PRIVATE));
        $taskId = $this->idForUid('t');
        $journalId = $this->idForUid('j');
        $this->seedDependents($taskId, 'alice', 1);
        $this->seedDependents($journalId, 'alice', 2);

        $tasks->delete(new EventId($taskId));
        $journals->delete(new EventId($journalId));

        foreach (self::DEPENDENT_TABLES as $table) {
            $this->assertSame(0, $this->countFor($table, $taskId), "task: $table");
            $this->assertSame(0, $this->countFor($table, $journalId), "journal: $table");
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function liveOrders(): array
    {
        return [
            'pending, free' => ['P', 0],
            'pending, priced' => ['P', 500],
            'paid' => ['A', 500],
        ];
    }

    #[DataProvider('liveOrders')]
    public function testDeleteIsRefusedWhileEntryHasLiveTicketOrders(string $status, int $amount): void
    {
        $event = $this->saveEvent('alice', 'sold');
        $this->seedDependents($event, 'alice', 1);
        $this->pdo->exec("UPDATE webcal_ticket_order SET order_status = '$status', order_amount = $amount WHERE cal_id = $event");

        try {
            $this->events->delete(new EventId($event));
            $this->fail('delete must be refused while orders are live');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('ticket orders', $e->getMessage());
        }

        $this->assertSame(1, $this->countFor('webcal_entry', $event), 'entry kept');
        foreach (self::DEPENDENT_TABLES as $table) {
            $this->assertGreaterThan(0, $this->countFor($table, $event), "$table kept");
        }
    }

    public function testDeleteProceedsWhenOrdersAreCancelledOrRefunded(): void
    {
        $event = $this->saveEvent('alice', 'settled');
        $this->seedDependents($event, 'alice', 1);
        $this->pdo->exec("INSERT INTO webcal_ticket_order (order_id, ticket_type_id, cal_id, order_email, order_name, order_qty, order_amount, order_currency, order_status)
                          VALUES (999, 100, $event, 'r@example.com', 'R', 1, 500, 'USD', 'R')");

        $this->events->delete(new EventId($event));

        $this->assertSame(0, $this->countFor('webcal_ticket_order', $event));
        $this->assertSame(0, $this->countFor('webcal_entry', $event));
    }

    public function testAFreeRsvpDoesNotBlockDeleteAndGoesWithTheEntry(): void
    {
        // A free RSVP is stored PAID with amount 0: no money to refund.
        $event = $this->saveEvent('alice', 'meetup');
        $other = $this->saveEvent('bob', 'other meetup');
        $this->seedDependents($event, 'alice', 1);
        $this->seedDependents($other, 'bob', 2);
        $this->pdo->exec("UPDATE webcal_ticket_order SET order_status = 'A', order_amount = 0 WHERE cal_id IN ($event, $other)");

        $this->events->delete(new EventId($event));

        $this->assertSame(0, $this->countFor('webcal_entry', $event));
        $this->assertSame(0, $this->countFor('webcal_ticket_order', $event));
        $this->assertSame(0, $this->countFor('webcal_attendee', $event));
        $this->assertSame(1, $this->countFor('webcal_ticket_order', $other), "another entry's RSVP stays");
    }

    public function testDeleteOfUnknownIdIsNoOp(): void
    {
        $kept = $this->saveEvent('bob', 'kept');
        $this->seedDependents($kept, 'bob', 1);

        $this->events->delete(new EventId(999));

        foreach (self::DEPENDENT_TABLES as $table) {
            $this->assertGreaterThan(0, $this->countFor($table, $kept), "$table must be untouched");
        }
    }

    // ---- user delete -------------------------------------------------------

    public function testUserDeleteRemovesOwnedEntriesCategoriesViewsAndSessions(): void
    {
        $aliceEvent = $this->saveEvent('alice', 'alice-private');
        $bobEvent = $this->saveEvent('bob', 'bob-private');
        $this->seedDependents($aliceEvent, 'alice', 1);
        $this->seedDependents($bobEvent, 'bob', 2);

        // Composite-key collision: same cat_id for alice and bob.
        $this->pdo->exec("INSERT INTO webcal_categories (cat_id, cat_owner, cat_name) VALUES (10, 'alice', 'A'), (10, 'bob', 'B')");
        $this->pdo->exec("INSERT INTO webcal_entry_categories (cal_id, cat_id, cat_order, cat_owner) VALUES ($bobEvent, 10, 1, 'alice'), ($bobEvent, 10, 2, 'bob')");
        $this->pdo->exec("INSERT INTO webcal_view (cal_view_id, cal_owner, cal_name) VALUES (1, 'alice', 'AV'), (2, 'bob', 'BV')");
        $this->pdo->exec("INSERT INTO webcal_view_user (cal_view_id, cal_login) VALUES (1, 'bob'), (2, 'bob')");
        $this->pdo->exec("INSERT INTO webcal_tokens (cal_token, cal_type, cal_data, cal_created_at)
                          VALUES ('s-alice', 'session', 'alice', 0), ('s-bob', 'session', 'bob', 0),
                                 ('c-alice', 'csrf', 'alice', 0)");

        (new PdoUserRepository($this->pdo))->delete('alice');

        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM webcal_entry WHERE cal_create_by = 'alice'"));
        foreach (self::DEPENDENT_TABLES as $table) {
            $this->assertSame(0, $this->countFor($table, $aliceEvent), "alice's entry $table");
        }
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM webcal_categories WHERE cat_owner = 'alice'"));
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM webcal_entry_categories WHERE cat_owner = 'alice'"));
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM webcal_view WHERE cal_owner = 'alice'"));
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM webcal_view_user WHERE cal_view_id = 1'));
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM webcal_tokens WHERE cal_type = 'session' AND cal_data = 'alice'"));

        // Cross-scope isolation: bob's rows at the same keys survive.
        $this->assertSame(1, $this->scalar("SELECT COUNT(*) FROM webcal_entry WHERE cal_create_by = 'bob'"));
        foreach (self::DEPENDENT_TABLES as $table) {
            $this->assertGreaterThan(0, $this->countFor($table, $bobEvent), "bob's entry $table");
        }
        $this->assertSame(1, $this->scalar("SELECT COUNT(*) FROM webcal_categories WHERE cat_id = 10 AND cat_owner = 'bob'"));
        $this->assertSame(2, $this->scalar("SELECT COUNT(*) FROM webcal_entry_categories WHERE cat_owner = 'bob'"));
        $this->assertSame(1, $this->scalar("SELECT COUNT(*) FROM webcal_view WHERE cal_owner = 'bob'"));
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM webcal_view_user WHERE cal_view_id = 2'));
        $this->assertSame(1, $this->scalar("SELECT COUNT(*) FROM webcal_tokens WHERE cal_data = 'bob'"));
        // A CSRF token's data is a session id, not a login; it is not touched.
        $this->assertSame(1, $this->scalar("SELECT COUNT(*) FROM webcal_tokens WHERE cal_token = 'c-alice'"));
    }

    public function testUserDeleteReassignsSharedEntriesToSuccessor(): void
    {
        $solo = $this->saveEvent('alice', 'alice-solo');
        $shared = $this->saveEvent('alice', 'alice-shared');
        $this->pdo->exec("INSERT INTO webcal_entry_user (cal_id, cal_login, cal_status) VALUES ($shared, 'bob', 'A')");

        (new PdoUserRepository($this->pdo))->delete('alice', 'admin');

        $this->assertSame(0, $this->countFor('webcal_entry', $solo), 'sole-attendee entry deleted');
        $this->assertSame('admin', $this->creatorOf($shared), 'shared entry reassigned');
        $this->assertSame(
            ['bob'],
            $this->participantsOf($shared),
            "bob keeps the meeting; alice's participation is gone"
        );
    }

    public function testUserDeleteWithoutSuccessorDeletesSharedEntriesToo(): void
    {
        $shared = $this->saveEvent('alice', 'alice-shared');
        $this->pdo->exec("INSERT INTO webcal_entry_user (cal_id, cal_login, cal_status) VALUES ($shared, 'bob', 'A')");

        (new PdoUserRepository($this->pdo))->delete('alice');

        $this->assertSame(0, $this->countFor('webcal_entry', $shared));
        $this->assertSame(0, $this->countFor('webcal_entry_user', $shared));
    }

    public function testUserDeleteIsRolledBackWhenAnOwnedEntryHasLiveOrders(): void
    {
        $sold = $this->saveEvent('alice', 'alice-sold');
        $this->seedDependents($sold, 'alice', 1);
        $this->pdo->exec("UPDATE webcal_ticket_order SET order_status = 'A', order_amount = 500 WHERE cal_id = $sold");
        $users = new PdoUserRepository($this->pdo);

        try {
            $users->delete('alice', 'admin');
            $this->fail('user delete must be refused');
        } catch (\DomainException) {
            // expected
        }

        $this->assertNotNull($users->findByLogin('alice'), 'user kept');
        $this->assertSame(1, $this->countFor('webcal_entry', $sold), 'entry kept');
    }

    public function testUserDeleteRemovesTemplatesOfOwnedReportsOnly(): void
    {
        foreach ([[1, 'alice'], [2, 'bob']] as [$id, $owner]) {
            $this->pdo->exec("INSERT INTO webcal_report (cal_login, cal_report_id, cal_report_type, cal_report_name, cal_time_range, cal_update_date)
                              VALUES ('$owner', $id, 'html', 'R$id', 11, 20260101)");
            $this->pdo->exec("INSERT INTO webcal_report_template (cal_report_id, cal_template_type, cal_template_text)
                              VALUES ($id, 'E', 'tpl-$owner')");
        }

        (new PdoUserRepository($this->pdo))->delete('alice');

        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM webcal_report_template WHERE cal_report_id = 1'));
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM webcal_report_template WHERE cal_report_id = 2'));
    }

    public function testRecreatedLoginInheritsNothing(): void
    {
        $this->saveEvent('alice', 'alice-secret');
        $this->pdo->exec("INSERT INTO webcal_categories (cat_id, cat_owner, cat_name) VALUES (3, 'alice', 'Old')");
        $users = new PdoUserRepository($this->pdo);

        $users->delete('alice');
        $users->save(new User('alice', 'New', 'Person', 'new@example.com', false, true));

        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM webcal_entry WHERE cal_create_by = 'alice'"));
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM webcal_categories WHERE cat_owner = 'alice'"));
    }

    public function testUserDeleteOfUnknownLoginIsNoOp(): void
    {
        $bobEvent = $this->saveEvent('bob', 'bob-only');
        $this->seedDependents($bobEvent, 'bob', 1);

        (new PdoUserRepository($this->pdo))->delete('nobody');

        $this->assertSame(1, $this->scalar("SELECT COUNT(*) FROM webcal_entry WHERE cal_create_by = 'bob'"));
        foreach (self::DEPENDENT_TABLES as $table) {
            $this->assertGreaterThan(0, $this->countFor($table, $bobEvent), $table);
        }
    }

    // ---- helpers -----------------------------------------------------------

    private function saveEvent(string $owner, string $uid): int
    {
        $this->events->save(new Event(
            new EventId(0),
            $uid,
            'Event ' . $uid,
            '',
            '',
            new \DateTimeImmutable('2026-04-01 10:00:00'),
            60,
            $owner,
            EventType::EVENT,
            AccessLevel::PRIVATE
        ));

        return $this->idForUid($uid);
    }

    private function idForUid(string $uid): int
    {
        $stmt = $this->pdo->prepare('SELECT cal_id FROM webcal_entry WHERE cal_uid = :uid');
        $stmt->execute(['uid' => $uid]);

        return (int) $stmt->fetchColumn();
    }

    private function seedDependents(int $calId, string $login, int $n): void
    {
        $base = $n * 100;
        $this->pdo->exec("INSERT INTO webcal_entry_repeats (cal_id, cal_type) VALUES ($calId, 'daily')");
        $this->pdo->exec("INSERT INTO webcal_entry_repeats_not (cal_id, cal_date, cal_exdate) VALUES ($calId, 20260402, 1)");
        $this->pdo->exec("INSERT INTO webcal_entry_categories (cal_id, cat_id, cat_order, cat_owner) VALUES ($calId, $base, 0, '$login')");
        $this->pdo->exec("INSERT INTO webcal_entry_ext_user (cal_id, cal_fullname) VALUES ($calId, 'Guest $n')");
        $this->pdo->exec("INSERT INTO webcal_site_extras (cal_id, cal_name, cal_type, cal_data) VALUES ($calId, 'note', 1, 'secret-$login')");
        $this->pdo->exec("INSERT INTO webcal_reminders (cal_id) VALUES ($calId)");
        $this->pdo->exec("INSERT INTO webcal_blob (cal_id, cal_login, cal_name, cal_type, cal_mod_date, cal_mod_time)
                          VALUES ($calId, '$login', 'file-$login.pdf', 'A', 20260101, 0)");
        $this->pdo->exec("INSERT INTO webcal_import_data (cal_import_id, cal_id, cal_login, cal_import_type) VALUES ($base, $calId, '$login', 'ical')");
        $this->pdo->exec("INSERT INTO webcal_ticket_type (ticket_type_id, cal_id, ticket_name) VALUES ($base, $calId, 'GA')");
        $this->pdo->exec("INSERT INTO webcal_ticket_order (order_id, ticket_type_id, cal_id, order_email, order_name, order_qty, order_amount, order_currency)
                          VALUES ($base, $base, $calId, 'buyer$n@example.com', 'Buyer', 1, 0, 'USD')");
        // Cancelled, so it does not block deleting the entry.
        $this->pdo->exec("UPDATE webcal_ticket_order SET order_status = 'X' WHERE order_id = $base");
        $this->pdo->exec("INSERT INTO webcal_attendee (attendee_id, order_id, cal_id, attendee_name, attendee_token)
                          VALUES ($base, $base, $calId, 'Buyer', 'token-$n-xxxxxxxxxxxxxxxx')");
    }

    private function creatorOf(int $calId): string
    {
        $stmt = $this->pdo->query("SELECT cal_create_by FROM webcal_entry WHERE cal_id = $calId");
        $this->assertNotFalse($stmt);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function participantsOf(int $calId): array
    {
        $stmt = $this->pdo->query("SELECT cal_login FROM webcal_entry_user WHERE cal_id = $calId ORDER BY cal_login");
        $this->assertNotFalse($stmt);

        /** @var list<string> */
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function countFor(string $table, int $calId): int
    {
        return $this->scalar("SELECT COUNT(*) FROM $table WHERE cal_id = $calId");
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->pdo->query($sql);
        $this->assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }
}
