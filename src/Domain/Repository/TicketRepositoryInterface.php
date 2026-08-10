<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\Entity\Attendee;
use WebCalendar\Core\Domain\Entity\TicketOrder;
use WebCalendar\Core\Domain\Entity\TicketType;

/**
 * Persistence for the ticketing domain (Epic 28): ticket types, orders
 * and attendees for one event family.
 */
interface TicketRepositoryInterface
{
    public function findTicketType(int $id): ?TicketType;

    /**
     * @return TicketType[] All ticket types for an event, in id order.
     */
    public function findTicketTypesForEvent(int $eventId): array;

    /**
     * Inserts when id is 0 (claiming the next id), updates otherwise.
     */
    public function saveTicketType(TicketType $type): TicketType;

    public function findOrder(int $id): ?TicketOrder;

    /**
     * @return TicketOrder[] All orders for an event, newest first.
     */
    public function findOrdersForEvent(int $eventId): array;

    /**
     * Quantity currently held against a ticket type's capacity —
     * the summed quantity of PENDING and PAID orders.
     */
    public function heldQuantity(int $ticketTypeId): int;

    /**
     * Inserts the order only if the ticket type's capacity still allows
     * its quantity, atomically (count-and-insert inside one
     * transaction). Returns the persisted order carrying its id, or
     * null when capacity would be exceeded.
     */
    public function createOrderIfCapacityAllows(TicketOrder $order, ?int $capacity): ?TicketOrder;

    /**
     * Persists an order's current state (status, external ref).
     */
    public function updateOrder(TicketOrder $order): void;

    /**
     * Inserts when id is 0 (claiming the next id), updates otherwise
     * (check-in stamps go through here).
     */
    public function saveAttendee(Attendee $attendee): Attendee;

    public function findAttendeeByToken(string $token): ?Attendee;

    /**
     * @return Attendee[] All attendees for an order, in id order.
     */
    public function findAttendeesForOrder(int $orderId): array;
}
