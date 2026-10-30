<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\ViewService;
use WebCalendar\Core\Domain\Repository\ViewRepositoryInterface;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Entity\View;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\ValueObject\ViewType;

final class ViewServiceTest extends TestCase
{
    /** @var ViewRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $viewRepository;
    private ViewService $viewService;

    protected function setUp(): void
    {
        $this->viewRepository = $this->createMock(ViewRepositoryInterface::class);
        $this->viewService = new ViewService($this->viewRepository);
    }

    public function testGetViewsForUser(): void
    {
        $login = 'jdoe';
        $globalView = new View(1, 'admin', 'Global View', ViewType::MONTH, true);
        $userView = new View(2, $login, 'My View', ViewType::WEEK, false);

        $this->viewRepository->expects($this->once())
            ->method('findAllGlobal')
            ->willReturn([$globalView]);

        $this->viewRepository->expects($this->once())
            ->method('findByOwner')
            ->with($login)
            ->willReturn([$userView]);

        $result = $this->viewService->getViewsForUser($login);
        
        $this->assertCount(2, $result);
        $this->assertContains($globalView, $result);
        $this->assertContains($userView, $result);
    }

    private function user(string $login, bool $admin = false): User
    {
        return new User($login, 'F', 'L', $login . '@example.com', $admin, true);
    }

    public function testOwnerCreatesPersonalView(): void
    {
        $view = new View(7, 'alice', 'Mine', ViewType::WEEK);
        $this->viewRepository->method('findById')->willReturn(null);
        $this->viewRepository->expects($this->once())->method('save')->with($view);

        $this->viewService->createView($view, $this->user('alice'));
    }

    public function testCreateCannotOverwriteAnExistingView(): void
    {
        // bob's view 7 already exists; alice re-creates id 7 as her own.
        $this->viewRepository->method('findById')
            ->willReturn(new View(7, 'bob', 'Bobs', ViewType::WEEK));
        $this->viewRepository->expects($this->never())->method('save');

        $this->expectException(\DomainException::class);
        $this->viewService->createView(new View(7, 'alice', 'Taken', ViewType::WEEK), $this->user('alice'));
    }

    public function testNonAdminCannotCreateViewForAnotherOwner(): void
    {
        $this->viewRepository->expects($this->never())->method('save');

        $this->expectException(AuthorizationException::class);
        $this->viewService->createView(new View(8, 'bob', 'Forged', ViewType::WEEK), $this->user('alice'));
    }

    public function testNonAdminCannotCreateGlobalView(): void
    {
        $this->viewRepository->expects($this->never())->method('save');

        $this->expectException(AuthorizationException::class);
        $this->viewService->createView(new View(8, 'alice', 'Site', ViewType::WEEK, true), $this->user('alice'));
    }

    public function testAdminCanCreateGlobalView(): void
    {
        $this->viewRepository->method('findById')->willReturn(null);
        $this->viewRepository->expects($this->once())->method('save');

        $this->viewService->createView(new View(8, 'admin', 'Site', ViewType::WEEK, true), $this->user('admin', true));
    }

    public function testUpdateChecksTheStoredOwner(): void
    {
        $this->viewRepository->method('findById')
            ->willReturn(new View(7, 'bob', 'Bobs', ViewType::WEEK));
        $this->viewRepository->expects($this->never())->method('save');

        $this->expectException(AuthorizationException::class);
        $this->viewService->updateView(new View(7, 'alice', 'Taken', ViewType::WEEK), $this->user('alice'));
    }

    public function testOwnerCannotReassignOrPublishViaUpdate(): void
    {
        $this->viewRepository->method('findById')
            ->willReturn(new View(7, 'alice', 'Mine', ViewType::WEEK));
        $this->viewRepository->expects($this->never())->method('save');

        $this->expectException(AuthorizationException::class);
        $this->viewService->updateView(new View(7, 'alice', 'Mine', ViewType::WEEK, true), $this->user('alice'));
    }

    public function testOwnerUpdatesOwnView(): void
    {
        $this->viewRepository->method('findById')
            ->willReturn(new View(7, 'alice', 'Mine', ViewType::WEEK));
        $renamed = new View(7, 'alice', 'Renamed', ViewType::MONTH);
        $this->viewRepository->expects($this->once())->method('save')->with($renamed);

        $this->viewService->updateView($renamed, $this->user('alice'));
    }

    public function testUpdateOfMissingViewThrows(): void
    {
        $this->viewRepository->method('findById')->willReturn(null);

        $this->expectException(\DomainException::class);
        $this->viewService->updateView(new View(9, 'alice', 'Mine', ViewType::WEEK), $this->user('alice'));
    }
}
