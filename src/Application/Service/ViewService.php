<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Entity\View;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Repository\ViewRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for managing custom calendar views.
 */
final readonly class ViewService
{
    private LoggerInterface $logger;

    public function __construct(
        private ViewRepositoryInterface $viewRepository,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
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

    /**
     * Creates a new view.
     */
    public function createView(View $view, User $actor): void
    {
        $this->logger->info('View created', ['id' => $view->id(), 'actor' => $actor->login()]);
        $this->viewRepository->save($view);
    }

    /**
     * Deletes a view.
     *
     * @throws \DomainException if the view does not exist.
     * @throws AuthorizationException if actor is not the owner or admin
     */
    public function deleteView(int $id, User $actor): void
    {
        $view = $this->viewRepository->findById($id);
        if ($view === null) {
            throw new \DomainException(sprintf('View with ID %d not found.', $id));
        }

        $this->assertCanModify($view, $actor, 'delete view');
        $this->logger->info('View deleted', ['id' => $id, 'actor' => $actor->login()]);
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
     *
     * @throws \DomainException if the view does not exist.
     * @throws AuthorizationException if actor is not the owner or admin
     * @param string[] $logins
     */
    public function setViewUsers(int $viewId, array $logins, User $actor): void
    {
        $view = $this->viewRepository->findById($viewId);
        if ($view === null) {
            throw new \DomainException(sprintf('View with ID %d not found.', $viewId));
        }

        $this->assertCanModify($view, $actor, 'modify view users');
        $this->logger->info('View users updated', ['view_id' => $viewId, 'users' => $logins, 'actor' => $actor->login()]);
        $this->viewRepository->setUsers($viewId, $logins);
    }

    /**
     * Asserts that the actor can modify the view.
     */
    private function assertCanModify(View $view, User $actor, string $action): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if ($view->owner() === $actor->login()) {
            return;
        }

        throw AuthorizationException::notOwner($action, $view->id(), $actor->login());
    }
}
