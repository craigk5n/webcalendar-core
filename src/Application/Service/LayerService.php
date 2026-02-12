<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Layer;
use WebCalendar\Core\Domain\Repository\LayerRepositoryInterface;

/**
 * Service for managing calendar layers (overlays).
 */
final readonly class LayerService
{
    public function __construct(
        private LayerRepositoryInterface $layerRepository
    ) {
    }

    /**
     * Gets all layers for a specific user.
     * 
     * @return Layer[]
     */
    public function getLayersForUser(string $login): array
    {
        return $this->layerRepository->findByOwner($login);
    }

    public function addLayer(Layer $layer): void
    {
        $this->layerRepository->save($layer);
    }

    public function deleteLayer(int $id): void
    {
        $this->layerRepository->delete($id);
    }
}
