<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

use WebCalendar\Core\Domain\ValueObject\OrganizerId;

/**
 * Domain entity representing a saved event Organizer — a reusable
 * person or group responsible for events, with contact details. Events
 * reference an organizer by id; the RFC 5545 ORGANIZER property maps
 * from these fields in the iCal layer.
 */
final class Organizer
{
    public function __construct(
        private readonly OrganizerId $id,
        private readonly string $name,
        private readonly ?string $email = null,
        private readonly ?string $phone = null,
        private readonly ?string $url = null,
    ) {
        if (empty(trim($this->name))) {
            throw new \InvalidArgumentException('Organizer name cannot be empty.');
        }
        if (
            $this->email !== null
            && filter_var($this->email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new \InvalidArgumentException(
                sprintf('Organizer email "%s" is not a valid address.', $this->email)
            );
        }
    }

    public function id(): OrganizerId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    /**
     * Return a copy of this organizer carrying a different identity — how
     * the repository reconciles an unsaved organizer (id 0) with its
     * stored row.
     */
    public function withId(OrganizerId $id): self
    {
        return new self(
            $id,
            $this->name,
            $this->email,
            $this->phone,
            $this->url,
        );
    }
}
