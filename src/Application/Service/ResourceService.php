<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Resource;
use WebCalendar\Core\Domain\Repository\ResourceRepositoryInterface;

/**
 * Service for managing shared resources (non-user calendars).
 */
final readonly class ResourceService
{
    public function __construct(
        private ResourceRepositoryInterface $resourceRepository
    ) {
    }

    /**
     * @return Resource[]
     */
    public function getAllResources(): array
    {
        return $this->resourceRepository->findAll();
    }

    public function getResourceByLogin(string $login): ?Resource
    {
        return $this->resourceRepository->findByLogin($login);
    }

    public function createResource(Resource $resource): void
    {
        $this->resourceRepository->save($resource);
    }

    public function updateResource(Resource $resource): void
    {
        $this->resourceRepository->save($resource);
    }

    public function deleteResource(string $login): void
    {
        $this->resourceRepository->delete($login);
    }

    /**
     * @return Resource[]
     */
    public function getResourcesManagedBy(string $adminLogin): array
    {
        return $this->resourceRepository->findByAdmin($adminLogin);
    }
}
