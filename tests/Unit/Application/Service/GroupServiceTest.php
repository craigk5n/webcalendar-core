<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\GroupService;
use WebCalendar\Core\Domain\Repository\GroupRepositoryInterface;
use WebCalendar\Core\Domain\Entity\Group;

final class GroupServiceTest extends TestCase
{
    /** @var GroupRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $groupRepository;
    private GroupService $groupService;

    protected function setUp(): void
    {
        $this->groupRepository = $this->createMock(GroupRepositoryInterface::class);
        $this->groupService = new GroupService($this->groupRepository);
    }

    public function testGetGroupsForUser(): void
    {
        $login = 'jdoe';
        $group = new Group(1, $login, 'Team A', new \DateTimeImmutable());

        $this->groupRepository->expects($this->once())
            ->method('findByOwner')
            ->with($login)
            ->willReturn([$group]);

        $result = $this->groupService->getGroupsByOwner($login);
        
        $this->assertCount(1, $result);
        $this->assertSame($group, $result[0]);
    }

    public function testAddMember(): void
    {
        $groupId = 1;
        $login = 'asmith';

        $this->groupRepository->expects($this->once())
            ->method('addMember')
            ->with($groupId, $login);

        $this->groupService->addMember($groupId, $login);
    }
}
