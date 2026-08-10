<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Venue;
use WebCalendar\Core\Domain\Repository\VenueRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\VenueId;

/**
 * Application service for saved Venues: CRUD, the imports'
 * match-or-create seam, and merge (dedupe).
 */
final class VenueService
{
    public function __construct(
        private readonly VenueRepositoryInterface $repository,
    ) {
    }

    public function getVenue(VenueId $id): ?Venue
    {
        return $this->repository->findById($id);
    }

    /**
     * @return Venue[] Ordered by name.
     */
    public function getAllVenues(): array
    {
        return $this->repository->findAll();
    }

    public function saveVenue(Venue $venue): Venue
    {
        return $this->repository->save($venue);
    }

    /**
     * Returns the venue already stored under this name (case-insensitive),
     * or saves and returns the given one. Import paths use this so
     * repeated imports never duplicate venues.
     */
    public function matchOrCreate(Venue $venue): Venue
    {
        return $this->repository->findByName($venue->name())
            ?? $this->repository->save($venue);
    }

    /**
     * Events referencing the venue keep their rows; the reference is
     * nulled, never cascaded.
     */
    public function deleteVenue(VenueId $id): void
    {
        $this->repository->delete($id);
    }

    /**
     * Merge one venue into another: its events are re-pointed at the
     * target, then the source is deleted.
     *
     * @throws \InvalidArgumentException On self-merge or a missing target.
     */
    public function merge(VenueId $from, VenueId $to): void
    {
        if ($from->equals($to)) {
            throw new \InvalidArgumentException('Cannot merge a venue into itself.');
        }
        if ($this->repository->findById($to) === null) {
            throw new \InvalidArgumentException(
                sprintf('Merge target venue %d does not exist.', $to->value())
            );
        }

        $this->repository->reassignEvents($from, $to);
        $this->repository->delete($from);
    }

    public function countEvents(VenueId $id): int
    {
        return $this->repository->countEvents($id);
    }
}
