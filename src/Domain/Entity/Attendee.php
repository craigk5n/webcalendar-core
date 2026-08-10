<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

/**
 * One admission — Epic 28. An order for quantity N yields N attendees,
 * each with its own check-in token (the QR payload). checkedInAt is
 * epoch seconds, null until scanned.
 */
final class Attendee
{
    public function __construct(
        private readonly int $id,
        private readonly int $orderId,
        private readonly int $eventId,
        private readonly string $name,
        private readonly string $checkInToken,
        private readonly ?string $email = null,
        private readonly ?int $checkedInAt = null,
    ) {
        if (empty(trim($this->name))) {
            throw new \InvalidArgumentException('Attendee name cannot be empty.');
        }
        if (strlen($this->checkInToken) < 16) {
            throw new \InvalidArgumentException('Check-in token must be at least 16 characters.');
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function orderId(): int
    {
        return $this->orderId;
    }

    public function eventId(): int
    {
        return $this->eventId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function checkInToken(): string
    {
        return $this->checkInToken;
    }

    public function checkedInAt(): ?int
    {
        return $this->checkedInAt;
    }

    public function isCheckedIn(): bool
    {
        return $this->checkedInAt !== null;
    }

    /**
     * @throws \DomainException When already checked in (double scans are
     *         the fraud case check-in exists to catch).
     */
    public function checkIn(int $epoch): self
    {
        if ($this->checkedInAt !== null) {
            throw new \DomainException(sprintf('Attendee %d is already checked in.', $this->id));
        }

        return new self(
            $this->id,
            $this->orderId,
            $this->eventId,
            $this->name,
            $this->checkInToken,
            $this->email,
            $epoch,
        );
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->orderId,
            $this->eventId,
            $this->name,
            $this->checkInToken,
            $this->email,
            $this->checkedInAt,
        );
    }
}
