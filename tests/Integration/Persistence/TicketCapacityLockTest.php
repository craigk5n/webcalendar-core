<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\TicketOrder;
use WebCalendar\Core\Domain\Entity\TicketType;
use WebCalendar\Core\Domain\ValueObject\OrderStatus;
use WebCalendar\Core\Infrastructure\Persistence\PdoTicketRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

/**
 * createOrderIfCapacityAllows() must take a row lock before it reads the held
 * quantity and allocates an id.
 *
 * The transaction alone does not make that sequence safe. heldQuantity() is a
 * plain SELECT and nextId() is MAX(order_id) + 1, so two concurrent orders on
 * a snapshot isolation level read the same counts and compute the same
 * primary key — surfacing as a duplicate-key error on what is, for RSVP, a
 * public anonymous endpoint.
 *
 * Real concurrency is not reproducible in this suite (SQLite serialises
 * writes, and the in-memory database is one connection), so these tests pin
 * the behaviour that closes the race: the statement is issued on drivers that
 * support it and skipped on the one that does not.
 */
final class TicketCapacityLockTest extends RepositoryTestCase
{
    private PdoTicketRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PdoTicketRepository($this->pdo);
    }

    protected function createPdo(string $dbUrl, ?string $dbUser, ?string $dbPass): PDO
    {
        return new DriverSpoofingPdo($dbUrl, $dbUser, $dbPass);
    }

    private function spoof(): DriverSpoofingPdo
    {
        $pdo = $this->pdo;
        self::assertInstanceOf(DriverSpoofingPdo::class, $pdo);

        return $pdo;
    }

    private function ticketType(?int $capacity): TicketType
    {
        return $this->repository->saveTicketType(new TicketType(
            id: 0,
            eventId: 100,
            name: 'RSVP',
            priceMinor: 0,
            currency: 'USD',
            capacity: $capacity,
        ));
    }

    private function order(int $typeId, int $quantity): TicketOrder
    {
        return new TicketOrder(
            id: 0,
            ticketTypeId: $typeId,
            eventId: 100,
            email: 'guest@example.com',
            name: 'Guest',
            quantity: $quantity,
            amountMinor: 0,
            currency: 'USD',
            status: OrderStatus::PAID,
            createdAt: 1_765_000_000,
        );
    }

    /** @return list<string> */
    private function lockStatements(): array
    {
        return array_values(array_filter(
            $this->spoof()->preparedSql,
            static fn (string $sql): bool => str_contains($sql, 'FOR UPDATE'),
        ));
    }

    public function testLocksTheTicketTypeRowOnMysql(): void
    {
        $type = $this->ticketType(10);

        $this->spoof()->spoofDriver = 'mysql';
        $this->spoof()->preparedSql = [];

        $order = $this->repository->createOrderIfCapacityAllows($this->order($type->id(), 2), $type->capacity());

        self::assertNotNull($order, 'the order is still written with the lock in place');

        $locks = $this->lockStatements();
        self::assertCount(1, $locks, 'exactly one row lock per order attempt');
        self::assertStringContainsString('webcal_ticket_type', $locks[0]);
        self::assertStringContainsString('ticket_type_id = :id', $locks[0]);
    }

    public function testLocksEvenWhenCapacityIsUnlimited(): void
    {
        // nextId()'s MAX + 1 races regardless of capacity, so an unlimited
        // ticket type must still serialise.
        $type = $this->ticketType(null);

        $this->spoof()->spoofDriver = 'mysql';
        $this->spoof()->preparedSql = [];

        $this->repository->createOrderIfCapacityAllows($this->order($type->id(), 1), $type->capacity());

        self::assertCount(1, $this->lockStatements());
    }

    public function testSkipsTheLockOnSqlite(): void
    {
        $type = $this->ticketType(10);

        $this->spoof()->spoofDriver = 'sqlite';
        $this->spoof()->preparedSql = [];

        $order = $this->repository->createOrderIfCapacityAllows($this->order($type->id(), 2), $type->capacity());

        self::assertNotNull($order);
        self::assertSame([], $this->lockStatements(), 'SQLite has no FOR UPDATE and serialises writes itself');
    }

    public function testCapacityIsStillEnforcedWithTheLockHeld(): void
    {
        $type = $this->ticketType(4);
        $this->spoof()->spoofDriver = 'mysql';

        $first = $this->repository->createOrderIfCapacityAllows($this->order($type->id(), 3), $type->capacity());
        self::assertNotNull($first);
        self::assertNull(
            $this->repository->createOrderIfCapacityAllows($this->order($type->id(), 2), $type->capacity()),
            'the lock must not change the refusal behaviour'
        );
    }
}
