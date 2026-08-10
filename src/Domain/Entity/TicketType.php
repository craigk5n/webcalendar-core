<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

/**
 * A purchasable (or free/RSVP) ticket class for one event — Epic 28.
 *
 * Money is integer minor units (cents); price 0 means RSVP. Capacity
 * null means unlimited. The sale window is epoch seconds (the
 * webcal_rate_limits precedent), null-open on either end.
 */
final class TicketType
{
    public function __construct(
        private readonly int $id,
        private readonly int $eventId,
        private readonly string $name,
        private readonly int $priceMinor = 0,
        private readonly string $currency = 'USD',
        private readonly ?int $capacity = null,
        private readonly ?int $saleStart = null,
        private readonly ?int $saleEnd = null,
        private readonly bool $enabled = true,
    ) {
        if (empty(trim($this->name))) {
            throw new \InvalidArgumentException('Ticket type name cannot be empty.');
        }
        if ($this->priceMinor < 0) {
            throw new \InvalidArgumentException('Ticket price cannot be negative.');
        }
        if (preg_match('/^[A-Z]{3}$/', $this->currency) !== 1) {
            throw new \InvalidArgumentException('Currency must be a 3-letter uppercase code.');
        }
        if ($this->capacity !== null && $this->capacity < 1) {
            throw new \InvalidArgumentException('Capacity must be positive (null for unlimited).');
        }
        if ($this->saleStart !== null && $this->saleEnd !== null && $this->saleEnd < $this->saleStart) {
            throw new \InvalidArgumentException('Sale window cannot end before it starts.');
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function eventId(): int
    {
        return $this->eventId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function priceMinor(): int
    {
        return $this->priceMinor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function capacity(): ?int
    {
        return $this->capacity;
    }

    public function saleStart(): ?int
    {
        return $this->saleStart;
    }

    public function saleEnd(): ?int
    {
        return $this->saleEnd;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isFree(): bool
    {
        return $this->priceMinor === 0;
    }

    /**
     * Whether sales are open at the given instant (epoch seconds).
     */
    public function isOnSaleAt(int $epoch): bool
    {
        if (!$this->enabled) {
            return false;
        }
        if ($this->saleStart !== null && $epoch < $this->saleStart) {
            return false;
        }
        return $this->saleEnd === null || $epoch <= $this->saleEnd;
    }
}
