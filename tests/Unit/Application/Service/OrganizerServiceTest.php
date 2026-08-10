<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\OrganizerService;
use WebCalendar\Core\Domain\Entity\Organizer;
use WebCalendar\Core\Domain\Repository\OrganizerRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\OrganizerId;

final class OrganizerServiceTest extends TestCase
{
    /** @var OrganizerRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private OrganizerRepositoryInterface $repository;

    private OrganizerService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(OrganizerRepositoryInterface::class);
        $this->service = new OrganizerService($this->repository);
    }

    public function testMatchOrCreateReturnsTheExistingOrganizerByName(): void
    {
        $existing = new Organizer(id: new OrganizerId(4), name: 'Alice');
        $this->repository->method('findByName')->with('Alice')->willReturn($existing);
        $this->repository->expects($this->never())->method('save');

        $this->assertSame($existing, $this->service->matchOrCreate(new Organizer(id: new OrganizerId(0), name: 'Alice')));
    }

    public function testMatchOrCreateSavesWhenTheNameIsUnknown(): void
    {
        $fresh = new Organizer(id: new OrganizerId(0), name: 'Bob');
        $saved = $fresh->withId(new OrganizerId(9));
        $this->repository->method('findByName')->willReturn(null);
        $this->repository->expects($this->once())->method('save')->with($fresh)->willReturn($saved);

        $this->assertSame($saved, $this->service->matchOrCreate($fresh));
    }

    public function testMergeReassignsThenDeletesTheSource(): void
    {
        $from = new OrganizerId(1);
        $to = new OrganizerId(2);
        $this->repository->method('findById')->willReturn(new Organizer(id: $to, name: 'Kept'));
        $this->repository->expects($this->once())->method('reassignEvents')->with($from, $to);
        $this->repository->expects($this->once())->method('delete')->with($from);

        $this->service->merge($from, $to);
    }

    public function testSelfMergeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->merge(new OrganizerId(3), new OrganizerId(3));
    }
}
