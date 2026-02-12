<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\View;
use WebCalendar\Core\Domain\Repository\ViewRepositoryInterface;

/**
 * Service for managing custom calendar views.
 */
final readonly class ViewService
{
    public function __construct(
        private ViewRepositoryInterface $viewRepository
    ) {
    }

    /**
     * Gets all views accessible to a user (global + user-owned).
     * 
     * @return View[]
     */
    public function getViewsForUser(string $login): array
    {
        $global = $this->viewRepository->findAllGlobal();
        $personal = $this->viewRepository->findByOwner($login);
        
        return array_merge($global, $personal);
    }

    public function createView(View $view): void
    {
        $this->viewRepository->save($view);
    }

    public function deleteView(int $id): void
    {
        $this->viewRepository->delete($id);
    }

    /**
     * Gets all users assigned to a view.
     * @return string[]
     */
    public function getViewUsers(int $viewId): array
    {
        return $this->viewRepository->getUsers($viewId);
    }

    /**
     * Assigns users to a view.
     * @param string[] $logins
     */
    public function setViewUsers(int $viewId, array $logins): void
    {
        $this->viewRepository->setUsers($viewId, $logins);
    }
}
