<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

use WebCalendar\Core\Domain\ValueObject\VenueId;

/**
 * Domain entity representing a saved event Venue.
 *
 * Promotes the legacy free-text location string to a first-class,
 * reusable place: postal address, coordinates for map views, and
 * contact details. Events reference a venue by id while keeping their
 * location string for backward compatibility (the venue name wins when
 * both are set).
 */
final class Venue
{
    public function __construct(
        private readonly VenueId $id,
        private readonly string $name,
        private readonly ?string $address = null,
        private readonly ?string $city = null,
        private readonly ?string $state = null,
        private readonly ?string $zip = null,
        private readonly ?string $country = null,
        private readonly ?float $latitude = null,
        private readonly ?float $longitude = null,
        private readonly ?string $url = null,
        private readonly ?string $phone = null,
    ) {
        if (empty(trim($this->name))) {
            throw new \InvalidArgumentException('Venue name cannot be empty.');
        }
        if (($this->latitude === null) !== ($this->longitude === null)) {
            throw new \InvalidArgumentException(
                'Venue coordinates must be provided as a latitude/longitude pair.'
            );
        }
        if ($this->latitude !== null && ($this->latitude < -90.0 || $this->latitude > 90.0)) {
            throw new \InvalidArgumentException('Venue latitude must be between -90 and 90.');
        }
        if ($this->longitude !== null && ($this->longitude < -180.0 || $this->longitude > 180.0)) {
            throw new \InvalidArgumentException('Venue longitude must be between -180 and 180.');
        }
    }

    public function id(): VenueId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function address(): ?string
    {
        return $this->address;
    }

    public function city(): ?string
    {
        return $this->city;
    }

    public function state(): ?string
    {
        return $this->state;
    }

    public function zip(): ?string
    {
        return $this->zip;
    }

    public function country(): ?string
    {
        return $this->country;
    }

    public function latitude(): ?float
    {
        return $this->latitude;
    }

    public function longitude(): ?float
    {
        return $this->longitude;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Return a copy of this venue carrying a different identity — how the
     * repository reconciles an unsaved venue (id 0) with its stored row.
     */
    public function withId(VenueId $id): self
    {
        return new self(
            $id,
            $this->name,
            $this->address,
            $this->city,
            $this->state,
            $this->zip,
            $this->country,
            $this->latitude,
            $this->longitude,
            $this->url,
            $this->phone,
        );
    }
}
