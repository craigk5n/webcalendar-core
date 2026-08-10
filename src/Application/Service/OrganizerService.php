<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Organizer;
use WebCalendar\Core\Domain\Repository\OrganizerRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\OrganizerId;

/**
 * Application service for saved Organizers: CRUD, the imports'
 * match-or-create seam, and merge (dedupe).
 */
final class OrganizerService
{
    public function __construct(
        private readonly OrganizerRepositoryInterface $repository,
    ) {
    }

    public function getOrganizer(OrganizerId $id): ?Organizer
    {
        return $this->repository->findById($id);
    }

    /**
     * @return Organizer[] Ordered by name.
     */
    public function getAllOrganizers(): array
    {
        return $this->repository->findAll();
    }

    public function saveOrganizer(Organizer $organizer): Organizer
    {
        return $this->repository->save($organizer);
    }

    /**
     * Returns the organizer already stored under this name
     * (case-insensitive), or saves and returns the given one. Import
     * paths use this so repeated imports never duplicate organizers.
     */
    public function matchOrCreate(Organizer $organizer): Organizer
    {
        return $this->repository->findByName($organizer->name())
            ?? $this->repository->save($organizer);
    }

    /**
     * Events referencing the organizer keep their rows; the reference is
     * nulled, never cascaded.
     */
    public function deleteOrganizer(OrganizerId $id): void
    {
        $this->repository->delete($id);
    }

    /**
     * Merge one organizer into another: its events are re-pointed at the
     * target, then the source is deleted.
     *
     * @throws \InvalidArgumentException On self-merge or a missing target.
     */
    public function merge(OrganizerId $from, OrganizerId $to): void
    {
        if ($from->equals($to)) {
            throw new \InvalidArgumentException('Cannot merge an organizer into itself.');
        }
        if ($this->repository->findById($to) === null) {
            throw new \InvalidArgumentException(
                sprintf('Merge target organizer %d does not exist.', $to->value())
            );
        }

        $this->repository->reassignEvents($from, $to);
        $this->repository->delete($from);
    }

    public function countEvents(OrganizerId $id): int
    {
        return $this->repository->countEvents($id);
    }
}
