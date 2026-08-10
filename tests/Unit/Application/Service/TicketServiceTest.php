<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Contract\PaymentException;
use WebCalendar\Core\Application\Contract\PaymentProviderInterface;
use WebCalendar\Core\Application\Contract\PaymentSession;
use WebCalendar\Core\Application\Service\TicketService;
use WebCalendar\Core\Domain\Entity\Attendee;
use WebCalendar\Core\Domain\Entity\TicketOrder;
use WebCalendar\Core\Domain\Entity\TicketType;
use WebCalendar\Core\Domain\Repository\TicketRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\OrderStatus;

/**
 * Epic 28.2 — purchase/RSVP, payment confirmation, refunds, check-in.
 * The repository double persists in arrays so id-claiming and updates
 * behave like the real one; the clock and token generator are pinned.
 */
final class TicketServiceTest extends TestCase
{
    private const NOW = 1_765_000_000;

    /** @var TicketRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private TicketRepositoryInterface $tickets;

    /** @var PaymentProviderInterface&\PHPUnit\Framework\MockObject\MockObject */
    private PaymentProviderInterface $payments;

    /** @var array<int, TicketOrder> */
    private array $orders = [];

    /** @var array<int, Attendee> */
    private array $attendees = [];

    private int $tokenCounter = 0;

    protected function setUp(): void
    {
        $this->orders = [];
        $this->attendees = [];
        $this->tokenCounter = 0;

        $this->tickets = $this->createMock(TicketRepositoryInterface::class);
        $this->payments = $this->createMock(PaymentProviderInterface::class);

        $this->tickets->method('createOrderIfCapacityAllows')->willReturnCallback(
            function (TicketOrder $order, ?int $capacity): ?TicketOrder {
                if ($capacity !== null && $order->quantity() > $capacity) {
                    return null;
                }
                $persisted = $order->withId(count($this->orders) + 1);
                $this->orders[$persisted->id()] = $persisted;
                return $persisted;
            }
        );
        $this->tickets->method('updateOrder')->willReturnCallback(
            function (TicketOrder $order): void {
                $this->orders[$order->id()] = $order;
            }
        );
        $this->tickets->method('findOrder')->willReturnCallback(
            fn (int $id): ?TicketOrder => array_key_exists($id, $this->orders) ? $this->orders[$id] : null
        );
        $this->tickets->method('saveAttendee')->willReturnCallback(
            function (Attendee $attendee): Attendee {
                $persisted = $attendee->id() === 0 ? $attendee->withId(count($this->attendees) + 1) : $attendee;
                $this->attendees[$persisted->id()] = $persisted;
                return $persisted;
            }
        );
    }

    private function service(?PaymentProviderInterface $payments = null): TicketService
    {
        return new TicketService(
            $this->tickets,
            $payments,
            null,
            null,
            fn (): int => self::NOW,
            function (): string {
                return str_pad((string) (++$this->tokenCounter), 32, 'a');
            },
        );
    }

    private function wireType(TicketType $type): void
    {
        $this->tickets->method('findTicketType')->willReturn($type);
    }

    // ---- purchase: RSVP ----------------------------------------------------

    public function testFreePurchaseCompletesImmediatelyWithAttendees(): void
    {
        $this->wireType(new TicketType(7, 100, 'RSVP'));

        $result = $this->service()->purchase(7, 'buyer@example.com', 'Buyer', 2);

        $this->assertSame(OrderStatus::PAID, $result->order->status());
        $this->assertSame(0, $result->order->amountMinor());
        $this->assertNull($result->checkoutUrl);
        $this->assertCount(2, $result->attendees);
        $this->assertSame('Buyer', $result->attendees[0]->name());
        $this->assertSame('Buyer (guest 2)', $result->attendees[1]->name());
        $this->assertNotSame(
            $result->attendees[0]->checkInToken(),
            $result->attendees[1]->checkInToken(),
            'every admission has its own token'
        );
    }

    // ---- purchase: paid ----------------------------------------------------

    public function testPaidPurchaseStartsCheckoutAndStaysPending(): void
    {
        $this->wireType(new TicketType(7, 100, 'GA', 2500));
        $this->payments->expects($this->once())->method('createPayment')
            ->willReturn(new PaymentSession('pi-1', 'https://pay.example.com/pi-1'));

        $result = $this->service($this->payments)->purchase(7, 'buyer@example.com', 'Buyer', 2);

        $this->assertSame(OrderStatus::PENDING, $result->order->status());
        $this->assertSame(5000, $result->order->amountMinor());
        $this->assertSame('pi-1', $result->order->externalRef());
        $this->assertSame('https://pay.example.com/pi-1', $result->checkoutUrl);
        $this->assertSame([], $result->attendees, 'no admission before money');
    }

    public function testPaidPurchaseWithoutProviderIsRejected(): void
    {
        $this->wireType(new TicketType(7, 100, 'GA', 2500));

        $this->expectException(\LogicException::class);
        $this->service()->purchase(7, 'buyer@example.com', 'Buyer', 1);
    }

    public function testProviderRefusalReleasesTheSeats(): void
    {
        $this->wireType(new TicketType(7, 100, 'GA', 2500));
        $this->payments->method('createPayment')->willThrowException(new PaymentException('declined'));

        try {
            $this->service($this->payments)->purchase(7, 'buyer@example.com', 'Buyer', 1);
            $this->fail('expected PaymentException');
        } catch (PaymentException) {
            // expected
        }

        $this->assertSame(OrderStatus::CANCELLED, $this->orders[1]->status(), 'held seats must not leak');
    }

    public function testSoldOutThrows(): void
    {
        $this->wireType(new TicketType(7, 100, 'Limited', 0, 'USD', 1));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('sold out');
        $this->service()->purchase(7, 'buyer@example.com', 'Buyer', 2);
    }

    public function testOffSaleTypeThrows(): void
    {
        $this->wireType(new TicketType(7, 100, 'Early Bird', 0, 'USD', null, null, self::NOW - 10));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('not on sale');
        $this->service()->purchase(7, 'buyer@example.com', 'Buyer', 1);
    }

    // ---- confirmPayment ----------------------------------------------------

    public function testConfirmPaymentMintsAttendeesOnceProviderAgrees(): void
    {
        $this->wireType(new TicketType(7, 100, 'GA', 2500));
        $this->payments->method('createPayment')->willReturn(new PaymentSession('pi-1', 'https://pay.example.com/pi-1'));
        $this->payments->method('isPaid')->with('pi-1')->willReturn(true);
        $service = $this->service($this->payments);
        $pending = $service->purchase(7, 'buyer@example.com', 'Buyer', 2);

        $result = $service->confirmPayment($pending->order->id());

        $this->assertSame(OrderStatus::PAID, $result->order->status());
        $this->assertCount(2, $result->attendees);
        $this->assertSame(OrderStatus::PAID, $this->orders[$result->order->id()]->status(), 'persisted');
    }

    public function testConfirmPaymentRejectsWhenProviderSaysUnpaid(): void
    {
        $this->wireType(new TicketType(7, 100, 'GA', 2500));
        $this->payments->method('createPayment')->willReturn(new PaymentSession('pi-1', 'https://pay.example.com/pi-1'));
        $this->payments->method('isPaid')->willReturn(false);
        $service = $this->service($this->payments);
        $pending = $service->purchase(7, 'buyer@example.com', 'Buyer', 1);

        try {
            $service->confirmPayment($pending->order->id());
            $this->fail('expected DomainException');
        } catch (\DomainException) {
            // expected
        }

        $this->assertSame(OrderStatus::PENDING, $this->orders[1]->status(), 'a forged webhook changes nothing');
    }

    // ---- refunds -----------------------------------------------------------

    public function testRefundGoesThroughTheProviderThenPersists(): void
    {
        $this->orders[9] = (new TicketOrder(
            9,
            7,
            100,
            'buyer@example.com',
            'Buyer',
            2,
            5000,
            'USD',
            OrderStatus::PAID,
            'pi-9',
        ));
        $this->payments->expects($this->once())->method('refund')->with('pi-9', 5000);

        $refunded = $this->service($this->payments)->refundOrder(9);

        $this->assertSame(OrderStatus::REFUNDED, $refunded->status());
        $this->assertSame(OrderStatus::REFUNDED, $this->orders[9]->status());
    }

    public function testFailedProviderRefundLeavesTheOrderPaid(): void
    {
        $this->orders[9] = (new TicketOrder(
            9,
            7,
            100,
            'buyer@example.com',
            'Buyer',
            1,
            2500,
            'USD',
            OrderStatus::PAID,
            'pi-9',
        ));
        $this->payments->method('refund')->willThrowException(new PaymentException('gateway down'));

        try {
            $this->service($this->payments)->refundOrder(9);
            $this->fail('expected PaymentException');
        } catch (PaymentException) {
            // expected
        }

        $this->assertSame(OrderStatus::PAID, $this->orders[9]->status());
    }

    // ---- check-in ----------------------------------------------------------

    public function testCheckInStampsAndPersists(): void
    {
        $token = str_pad('t1', 32, 'x');
        $this->tickets->method('findAttendeeByToken')->willReturnCallback(
            function (string $wanted) use ($token): ?Attendee {
                if ($wanted !== $token) {
                    return null;
                }
                return $this->attendees[1] ?? new Attendee(1, 9, 100, 'Guest', $token);
            }
        );

        $scanned = $this->service()->checkIn($token);

        $this->assertTrue($scanned->isCheckedIn());
        $this->assertSame(self::NOW, $scanned->checkedInAt());

        // Second scan of the same token is the fraud case.
        $this->expectException(\DomainException::class);
        $this->service()->checkIn($token);
    }

    public function testUnknownTokenIsRejected(): void
    {
        $this->tickets->method('findAttendeeByToken')->willReturn(null);

        $this->expectException(\DomainException::class);
        $this->service()->checkIn(str_pad('nope', 32, 'x'));
    }
}
