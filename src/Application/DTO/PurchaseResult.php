<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\DTO;

use WebCalendar\Core\Domain\Entity\Attendee;
use WebCalendar\Core\Domain\Entity\TicketOrder;

/**
 * Outcome of a ticket purchase (Epic 28). Free/RSVP purchases complete
 * immediately: the order is PAID and attendees exist. Paid purchases
 * return a PENDING order and the provider's checkout URL; attendees are
 * minted when the payment confirms.
 */
final class PurchaseResult
{
    /**
     * @param list<Attendee> $attendees Empty until payment confirms.
     */
    public function __construct(
        public readonly TicketOrder $order,
        public readonly array $attendees,
        public readonly ?string $checkoutUrl = null,
    ) {
    }
}
