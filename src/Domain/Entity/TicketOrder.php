<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

use WebCalendar\Core\Domain\ValueObject\OrderStatus;

/**
 * One ticket order — Epic 28. Immutable; state changes produce a copy
 * via withStatus(), which enforces the OrderStatus transition rules.
 * Money is integer minor units.
 */
final class TicketOrder
{
    public function __construct(
        private readonly int $id,
        private readonly int $ticketTypeId,
        private readonly int $eventId,
        private readonly string $email,
        private readonly string $name,
        private readonly int $quantity,
        private readonly int $amountMinor,
        private readonly string $currency,
        private readonly OrderStatus $status = OrderStatus::PENDING,
        private readonly ?string $externalRef = null,
        private readonly int $createdAt = 0,
    ) {
        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException(
                sprintf('Order email "%s" is not a valid address.', $this->email)
            );
        }
        if (empty(trim($this->name))) {
            throw new \InvalidArgumentException('Order name cannot be empty.');
        }
        if ($this->quantity < 1) {
            throw new \InvalidArgumentException('Order quantity must be at least 1.');
        }
        if ($this->amountMinor < 0) {
            throw new \InvalidArgumentException('Order amount cannot be negative.');
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function ticketTypeId(): int
    {
        return $this->ticketTypeId;
    }

    public function eventId(): int
    {
        return $this->eventId;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function externalRef(): ?string
    {
        return $this->externalRef;
    }

    public function createdAt(): int
    {
        return $this->createdAt;
    }

    /**
     * @throws \DomainException On an illegal status transition.
     */
    public function withStatus(OrderStatus $next, ?string $externalRef = null): self
    {
        if (!$this->status->canTransitionTo($next)) {
            throw new \DomainException(sprintf(
                'Order %d cannot go from %s to %s.',
                $this->id,
                $this->status->name,
                $next->name,
            ));
        }

        return new self(
            $this->id,
            $this->ticketTypeId,
            $this->eventId,
            $this->email,
            $this->name,
            $this->quantity,
            $this->amountMinor,
            $this->currency,
            $next,
            $externalRef ?? $this->externalRef,
            $this->createdAt,
        );
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->ticketTypeId,
            $this->eventId,
            $this->email,
            $this->name,
            $this->quantity,
            $this->amountMinor,
            $this->currency,
            $this->status,
            $this->externalRef,
            $this->createdAt,
        );
    }
}
