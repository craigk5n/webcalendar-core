<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use WebCalendar\Core\Domain\Entity\Attendee;
use WebCalendar\Core\Domain\Entity\TicketOrder;
use WebCalendar\Core\Domain\Entity\TicketType;
use WebCalendar\Core\Domain\ValueObject\OrderStatus;
use WebCalendar\Core\Infrastructure\Persistence\PdoTicketRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

final class PdoTicketRepositoryTest extends RepositoryTestCase
{
    private PdoTicketRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PdoTicketRepository($this->pdo);
    }

    private function order(int $typeId, int $quantity, OrderStatus $status = OrderStatus::PENDING): TicketOrder
    {
        return new TicketOrder(
            id: 0,
            ticketTypeId: $typeId,
            eventId: 100,
            email: 'buyer@example.com',
            name: 'Buyer',
            quantity: $quantity,
            amountMinor: $quantity * 2500,
            currency: 'USD',
            status: $status,
            createdAt: 1_765_000_000,
        );
    }

    public function testTicketTypeRoundTrips(): void
    {
        $saved = $this->repository->saveTicketType(new TicketType(
            id: 0,
            eventId: 100,
            name: 'General Admission',
            priceMinor: 2500,
            currency: 'USD',
            capacity: 50,
            saleStart: 1_760_000_000,
            saleEnd: 1_770_000_000,
        ));

        $this->assertGreaterThan(0, $saved->id());
        $found = $this->repository->findTicketType($saved->id());
        $this->assertNotNull($found);
        $this->assertSame('General Admission', $found->name());
        $this->assertSame(2500, $found->priceMinor());
        $this->assertSame(50, $found->capacity());
        $this->assertSame(1_760_000_000, $found->saleStart());

        // Update in place.
        $this->repository->saveTicketType(new TicketType($saved->id(), 100, 'GA (renamed)', 3000));
        $renamed = $this->repository->findTicketType($saved->id());
        $this->assertNotNull($renamed);
        $this->assertSame('GA (renamed)', $renamed->name());
        $this->assertNull($renamed->capacity(), 'update overwrites the whole row');
        $this->assertCount(1, $this->repository->findTicketTypesForEvent(100));
    }

    public function testOrderInsertsWhileCapacityAllowsAndRefusesBeyondIt(): void
    {
        $type = $this->repository->saveTicketType(new TicketType(0, 100, 'Limited', 1000, 'USD', 5));

        $first = $this->repository->createOrderIfCapacityAllows($this->order($type->id(), 3), $type->capacity());
        $this->assertNotNull($first);
        $this->assertGreaterThan(0, $first->id());

        $second = $this->repository->createOrderIfCapacityAllows($this->order($type->id(), 2), $type->capacity());
        $this->assertNotNull($second, 'exactly fills the capacity');

        $third = $this->repository->createOrderIfCapacityAllows($this->order($type->id(), 1), $type->capacity());
        $this->assertNull($third, 'sold out');
        $this->assertSame(5, $this->repository->heldQuantity($type->id()));
    }

    public function testCancelledOrdersReleaseTheirSeats(): void
    {
        $type = $this->repository->saveTicketType(new TicketType(0, 100, 'Limited', 1000, 'USD', 3));
        $order = $this->repository->createOrderIfCapacityAllows($this->order($type->id(), 3), $type->capacity());
        $this->assertNotNull($order);

        $this->repository->updateOrder($order->withStatus(OrderStatus::CANCELLED));

        $this->assertSame(0, $this->repository->heldQuantity($type->id()));
        $this->assertNotNull(
            $this->repository->createOrderIfCapacityAllows($this->order($type->id(), 2), $type->capacity()),
            'released seats are sellable again'
        );
    }

    public function testUnlimitedCapacityNeverRefuses(): void
    {
        $type = $this->repository->saveTicketType(new TicketType(0, 100, 'RSVP'));

        $order = $this->repository->createOrderIfCapacityAllows($this->order($type->id(), 500), $type->capacity());

        $this->assertNotNull($order);
    }

    public function testCapacityCountsOnlyTheOwnTicketType(): void
    {
        // Cross-scope isolation: another type's orders must not consume
        // this type's capacity.
        $limited = $this->repository->saveTicketType(new TicketType(0, 100, 'Limited', 1000, 'USD', 2));
        $other = $this->repository->saveTicketType(new TicketType(0, 100, 'Other', 1000, 'USD', 50));
        $this->repository->createOrderIfCapacityAllows($this->order($other->id(), 50), $other->capacity());

        $order = $this->repository->createOrderIfCapacityAllows($this->order($limited->id(), 2), $limited->capacity());

        $this->assertNotNull($order, "another type's 50 seats are not ours");
    }

    public function testOrderStatusAndExternalRefPersist(): void
    {
        $type = $this->repository->saveTicketType(new TicketType(0, 100, 'GA', 1000));
        $order = $this->repository->createOrderIfCapacityAllows($this->order($type->id(), 1), null);
        $this->assertNotNull($order);

        $this->repository->updateOrder($order->withStatus(OrderStatus::PAID, 'stripe-pi-42'));

        $found = $this->repository->findOrder($order->id());
        $this->assertNotNull($found);
        $this->assertSame(OrderStatus::PAID, $found->status());
        $this->assertSame('stripe-pi-42', $found->externalRef());
        $this->assertCount(1, $this->repository->findOrdersForEvent(100));
    }

    public function testAttendeeRoundTripsAndCheckInStampPersists(): void
    {
        $attendee = $this->repository->saveAttendee(new Attendee(
            id: 0,
            orderId: 7,
            eventId: 100,
            name: 'Guest One',
            checkInToken: 'abcdef0123456789abcdef0123456789',
            email: 'guest@example.com',
        ));

        $found = $this->repository->findAttendeeByToken('abcdef0123456789abcdef0123456789');
        $this->assertNotNull($found);
        $this->assertSame('Guest One', $found->name());
        $this->assertFalse($found->isCheckedIn());

        $this->repository->saveAttendee($found->checkIn(1_765_000_000));

        $scanned = $this->repository->findAttendeeByToken('abcdef0123456789abcdef0123456789');
        $this->assertNotNull($scanned);
        $this->assertSame(1_765_000_000, $scanned->checkedInAt());
        $this->assertCount(1, $this->repository->findAttendeesForOrder(7));
    }

    public function testUnknownTokenFindsNothing(): void
    {
        $this->assertNull($this->repository->findAttendeeByToken('no-such-token-0123456789'));
    }
}
