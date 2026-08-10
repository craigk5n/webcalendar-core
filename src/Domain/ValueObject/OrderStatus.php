<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Ticket order lifecycle (Epic 28). Legal transitions:
 * PENDING → PAID | CANCELLED, PAID → REFUNDED. RSVP (free) orders are
 * created directly as PAID.
 */
enum OrderStatus: string
{
    case PENDING = 'P';
    case PAID = 'A';
    case CANCELLED = 'X';
    case REFUNDED = 'R';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::PENDING => $next === self::PAID || $next === self::CANCELLED,
            self::PAID => $next === self::REFUNDED,
            self::CANCELLED, self::REFUNDED => false,
        };
    }

    /**
     * Statuses whose quantities count against a ticket type's capacity.
     *
     * @return list<self>
     */
    public static function capacityHolding(): array
    {
        return [self::PENDING, self::PAID];
    }
}
