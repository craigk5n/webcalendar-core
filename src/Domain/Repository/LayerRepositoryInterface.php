<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\Entity\Layer;

/**
 * Interface for Layer persistence operations.
 */
interface LayerRepositoryInterface
{
    /**
     * @return Layer[]
     */
    public function findByOwner(string $owner): array;

    public function save(Layer $layer): void;

    public function delete(int $id): void;
}
