<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WebCalendar\Core\Application\Contract\PaymentProviderInterface;
use WebCalendar\Core\Application\DTO\PurchaseResult;
use WebCalendar\Core\Domain\Entity\Attendee;
use WebCalendar\Core\Domain\Entity\TicketOrder;
use WebCalendar\Core\Domain\Entity\TicketType;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\TicketRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;
use WebCalendar\Core\Domain\ValueObject\OrderStatus;

/**
 * Ticket sales, RSVP, refunds and check-in — Epic 28.2. Zero-commission:
 * core computes amounts in integer minor units and delegates money
 * movement to the app's PaymentProviderInterface implementation; no
 * gateway SDK ever enters core.
 *
 * The clock and token generator are injectable for tests; defaults are
 * time() and 32-hex-char random tokens.
 */
final class TicketService
{
    private readonly LoggerInterface $logger;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @var \Closure(): string */
    private readonly \Closure $tokenGenerator;

    public function __construct(
        private readonly TicketRepositoryInterface $tickets,
        private readonly ?PaymentProviderInterface $payments = null,
        private readonly ?ActivityLogService $activityLog = null,
        ?LoggerInterface $logger = null,
        ?\Closure $clock = null,
        ?\Closure $tokenGenerator = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->clock = $clock ?? static fn (): int => time();
        $this->tokenGenerator = $tokenGenerator ?? static fn (): string => bin2hex(random_bytes(16));
    }

    public function createTicketType(TicketType $type, User $actor): TicketType
    {
        $saved = $this->tickets->saveTicketType($type);
        $this->logger->info('Ticket type saved', [
            'id' => $saved->id(),
            'event' => $saved->eventId(),
            'actor' => $actor->login(),
        ]);
        return $saved;
    }

    /**
     * Buy (or RSVP for) tickets. Free types complete immediately; paid
     * types return a PENDING order plus the provider's checkout URL.
     *
     * @throws \DomainException When the type is unknown, off sale, or
     *         sold out.
     * @throws \LogicException When a paid type is purchased but no
     *         payment provider is configured.
     * @throws \WebCalendar\Core\Application\Contract\PaymentException
     *         When the provider refuses — the pending order is cancelled.
     */
    public function purchase(int $ticketTypeId, string $email, string $name, int $quantity): PurchaseResult
    {
        $type = $this->tickets->findTicketType($ticketTypeId);
        if ($type === null) {
            throw new \DomainException(sprintf('Ticket type %d not found.', $ticketTypeId));
        }
        $now = ($this->clock)();
        if (!$type->isOnSaleAt($now)) {
            throw new \DomainException(sprintf('Ticket type "%s" is not on sale.', $type->name()));
        }
        if (!$type->isFree() && $this->payments === null) {
            throw new \LogicException('Paid tickets need a configured payment provider.');
        }

        $order = new TicketOrder(
            id: 0,
            ticketTypeId: $type->id(),
            eventId: $type->eventId(),
            email: $email,
            name: $name,
            quantity: $quantity,
            amountMinor: $type->priceMinor() * $quantity,
            currency: $type->currency(),
            status: $type->isFree() ? OrderStatus::PAID : OrderStatus::PENDING,
            createdAt: $now,
        );

        $persisted = $this->tickets->createOrderIfCapacityAllows($order, $type->capacity());
        if ($persisted === null) {
            throw new \DomainException(sprintf('Ticket type "%s" is sold out.', $type->name()));
        }

        if ($type->isFree()) {
            $attendees = $this->mintAttendees($persisted);
            $this->audit($persisted, 'RSVP completed');
            return new PurchaseResult($persisted, $attendees);
        }

        /** @var PaymentProviderInterface $payments -- guarded above */
        $payments = $this->payments;
        try {
            $session = $payments->createPayment($persisted);
        } catch (\Throwable $e) {
            // The seats must not stay held by an order that can never pay.
            $this->tickets->updateOrder($persisted->withStatus(OrderStatus::CANCELLED));
            throw $e;
        }

        $persisted = $persisted->withExternalRef($session->externalRef);
        $this->tickets->updateOrder($persisted);
        $this->audit($persisted, 'Checkout started');

        return new PurchaseResult($persisted, [], $session->checkoutUrl);
    }

    /**
     * Flip a pending order to PAID once the provider confirms the money
     * arrived (never trust the webhook payload alone), and mint the
     * attendees.
     *
     * @throws \DomainException When the order is unknown, not pending,
     *         has no payment reference, or the provider says unpaid.
     */
    public function confirmPayment(int $orderId): PurchaseResult
    {
        $order = $this->tickets->findOrder($orderId);
        if ($order === null) {
            throw new \DomainException(sprintf('Order %d not found.', $orderId));
        }
        $ref = $order->externalRef();
        if ($order->status() !== OrderStatus::PENDING || $ref === null) {
            throw new \DomainException(sprintf('Order %d is not awaiting payment.', $orderId));
        }
        if ($this->payments === null || !$this->payments->isPaid($ref)) {
            throw new \DomainException(sprintf('Order %d is not paid at the provider.', $orderId));
        }

        $paid = $order->withStatus(OrderStatus::PAID);
        $this->tickets->updateOrder($paid);
        $attendees = $this->mintAttendees($paid);
        $this->audit($paid, 'Payment confirmed');

        return new PurchaseResult($paid, $attendees);
    }

    /**
     * Cancel a pending order (seats release immediately).
     */
    public function cancelOrder(int $orderId): TicketOrder
    {
        $order = $this->tickets->findOrder($orderId);
        if ($order === null) {
            throw new \DomainException(sprintf('Order %d not found.', $orderId));
        }

        $cancelled = $order->withStatus(OrderStatus::CANCELLED);
        $this->tickets->updateOrder($cancelled);
        $this->audit($cancelled, 'Order cancelled');

        return $cancelled;
    }

    /**
     * Refund a paid order in full. The provider refunds first; if that
     * throws, the order stays PAID.
     */
    public function refundOrder(int $orderId): TicketOrder
    {
        $order = $this->tickets->findOrder($orderId);
        if ($order === null) {
            throw new \DomainException(sprintf('Order %d not found.', $orderId));
        }
        $ref = $order->externalRef();
        if ($order->status() !== OrderStatus::PAID || $ref === null) {
            throw new \DomainException(sprintf('Order %d is not refundable.', $orderId));
        }
        if ($this->payments === null) {
            throw new \LogicException('Refunds need a configured payment provider.');
        }

        $this->payments->refund($ref, $order->amountMinor());

        $refunded = $order->withStatus(OrderStatus::REFUNDED);
        $this->tickets->updateOrder($refunded);
        $this->audit($refunded, 'Order refunded');

        return $refunded;
    }

    /**
     * Scan one admission token. Double scans throw (the fraud case
     * check-in exists to catch) and do not re-stamp.
     *
     * @throws \DomainException On an unknown token or a repeat scan.
     */
    public function checkIn(string $token): Attendee
    {
        $attendee = $this->tickets->findAttendeeByToken($token);
        if ($attendee === null) {
            throw new \DomainException('Unknown check-in token.');
        }

        $scanned = $attendee->checkIn(($this->clock)());
        $this->tickets->saveAttendee($scanned);
        $this->logger->info('Attendee checked in', [
            'attendee' => $scanned->id(),
            'event' => $scanned->eventId(),
        ]);

        return $scanned;
    }

    /**
     * @return list<Attendee> One admission per quantity unit, each with
     *         its own token; the first carries the buyer's name.
     */
    private function mintAttendees(TicketOrder $order): array
    {
        $attendees = [];
        for ($i = 1; $i <= $order->quantity(); $i++) {
            $attendees[] = $this->tickets->saveAttendee(new Attendee(
                id: 0,
                orderId: $order->id(),
                eventId: $order->eventId(),
                name: $i === 1 ? $order->name() : sprintf('%s (guest %d)', $order->name(), $i),
                checkInToken: ($this->tokenGenerator)(),
                email: $i === 1 ? $order->email() : null,
            ));
        }
        return $attendees;
    }

    private function audit(TicketOrder $order, string $text): void
    {
        $this->activityLog?->log(
            $order->eventId(),
            $order->email(),
            null,
            ActivityLogType::EXTRA,
            sprintf('%s: order %d, %d × type %d.', $text, $order->id(), $order->quantity(), $order->ticketTypeId())
        );
        $this->logger->info($text, ['order' => $order->id(), 'status' => $order->status()->name]);
    }
}
