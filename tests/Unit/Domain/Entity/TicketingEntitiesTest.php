<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Attendee;
use WebCalendar\Core\Domain\Entity\TicketOrder;
use WebCalendar\Core\Domain\Entity\TicketType;
use WebCalendar\Core\Domain\ValueObject\OrderStatus;

/**
 * Epic 28.1 — the ticketing domain entities and the order state machine.
 */
final class TicketingEntitiesTest extends TestCase
{
    private function order(OrderStatus $status = OrderStatus::PENDING): TicketOrder
    {
        return new TicketOrder(
            id: 1,
            ticketTypeId: 2,
            eventId: 3,
            email: 'buyer@example.com',
            name: 'Buyer',
            quantity: 2,
            amountMinor: 5000,
            currency: 'USD',
            status: $status,
        );
    }

    // ---- TicketType --------------------------------------------------------

    public function testTicketTypeValidatesItsFields(): void
    {
        $type = new TicketType(
            id: 1,
            eventId: 3,
            name: 'General Admission',
            priceMinor: 2500,
            currency: 'USD',
            capacity: 100,
            saleStart: 1_760_000_000,
            saleEnd: 1_770_000_000,
        );

        $this->assertFalse($type->isFree());
        $this->assertTrue($type->isOnSaleAt(1_765_000_000));
        $this->assertFalse($type->isOnSaleAt(1_759_999_999), 'before the window');
        $this->assertFalse($type->isOnSaleAt(1_770_000_001), 'after the window');
    }

    public function testFreeTicketTypeIsRsvp(): void
    {
        $rsvp = new TicketType(id: 1, eventId: 3, name: 'RSVP');

        $this->assertTrue($rsvp->isFree());
        $this->assertNull($rsvp->capacity());
        $this->assertTrue($rsvp->isOnSaleAt(0), 'open-ended window');
    }

    public function testDisabledTicketTypeIsNeverOnSale(): void
    {
        $type = new TicketType(id: 1, eventId: 3, name: 'Paused', enabled: false);

        $this->assertFalse($type->isOnSaleAt(1_765_000_000));
    }

    public function testNegativePriceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TicketType(id: 1, eventId: 3, name: 'Bad', priceMinor: -1);
    }

    public function testBackwardsSaleWindowIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TicketType(id: 1, eventId: 3, name: 'Bad', saleStart: 200, saleEnd: 100);
    }

    public function testLowercaseCurrencyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TicketType(id: 1, eventId: 3, name: 'Bad', currency: 'usd');
    }

    // ---- TicketOrder state machine ----------------------------------------

    public function testPendingOrderCanBePaidWithAnExternalRef(): void
    {
        $paid = $this->order()->withStatus(OrderStatus::PAID, 'stripe-pi-123');

        $this->assertSame(OrderStatus::PAID, $paid->status());
        $this->assertSame('stripe-pi-123', $paid->externalRef());
    }

    public function testPendingOrderCanBeCancelled(): void
    {
        $this->assertSame(OrderStatus::CANCELLED, $this->order()->withStatus(OrderStatus::CANCELLED)->status());
    }

    public function testPaidOrderCanOnlyBeRefunded(): void
    {
        $paid = $this->order(OrderStatus::PAID);

        $this->assertSame(OrderStatus::REFUNDED, $paid->withStatus(OrderStatus::REFUNDED)->status());

        $this->expectException(\DomainException::class);
        $paid->withStatus(OrderStatus::CANCELLED);
    }

    public function testRefundedOrderIsTerminal(): void
    {
        $this->expectException(\DomainException::class);
        $this->order(OrderStatus::REFUNDED)->withStatus(OrderStatus::PAID);
    }

    public function testPendingCannotSkipToRefunded(): void
    {
        $this->expectException(\DomainException::class);
        $this->order()->withStatus(OrderStatus::REFUNDED);
    }

    public function testInvalidEmailIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TicketOrder(1, 2, 3, 'not-an-email', 'Buyer', 1, 0, 'USD');
    }

    public function testZeroQuantityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TicketOrder(1, 2, 3, 'buyer@example.com', 'Buyer', 0, 0, 'USD');
    }

    public function testCapacityHoldingStatuses(): void
    {
        $this->assertSame([OrderStatus::PENDING, OrderStatus::PAID], OrderStatus::capacityHolding());
    }

    // ---- Attendee check-in -------------------------------------------------

    public function testCheckInStampsTheTimeOnce(): void
    {
        $attendee = new Attendee(
            id: 1,
            orderId: 5,
            eventId: 3,
            name: 'Guest One',
            checkInToken: 'abcdef0123456789abcdef0123456789',
        );

        $this->assertFalse($attendee->isCheckedIn());

        $scanned = $attendee->checkIn(1_765_000_000);
        $this->assertTrue($scanned->isCheckedIn());
        $this->assertSame(1_765_000_000, $scanned->checkedInAt());

        $this->expectException(\DomainException::class);
        $scanned->checkIn(1_765_000_060);
    }

    public function testShortTokenIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Attendee(1, 5, 3, 'Guest', 'short-token');
    }
}
