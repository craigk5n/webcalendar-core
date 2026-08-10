<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\VenueService;
use WebCalendar\Core\Domain\Entity\Venue;
use WebCalendar\Core\Domain\Repository\VenueRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\VenueId;

final class VenueServiceTest extends TestCase
{
    /** @var VenueRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private VenueRepositoryInterface $repository;

    private VenueService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(VenueRepositoryInterface::class);
        $this->service = new VenueService($this->repository);
    }

    public function testMatchOrCreateReturnsTheExistingVenueByName(): void
    {
        $existing = new Venue(id: new VenueId(4), name: 'Community Hall');
        $this->repository->method('findByName')->with('Community Hall')->willReturn($existing);
        $this->repository->expects($this->never())->method('save');

        $this->assertSame($existing, $this->service->matchOrCreate(new Venue(id: new VenueId(0), name: 'Community Hall')));
    }

    public function testMatchOrCreateSavesWhenTheNameIsUnknown(): void
    {
        $fresh = new Venue(id: new VenueId(0), name: 'New Spot');
        $saved = $fresh->withId(new VenueId(9));
        $this->repository->method('findByName')->willReturn(null);
        $this->repository->expects($this->once())->method('save')->with($fresh)->willReturn($saved);

        $this->assertSame($saved, $this->service->matchOrCreate($fresh));
    }

    public function testMergeReassignsThenDeletesTheSource(): void
    {
        $from = new VenueId(1);
        $to = new VenueId(2);
        $this->repository->method('findById')->willReturn(new Venue(id: $to, name: 'Kept'));
        $this->repository->expects($this->once())->method('reassignEvents')->with($from, $to);
        $this->repository->expects($this->once())->method('delete')->with($from);

        $this->service->merge($from, $to);
    }

    public function testSelfMergeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->merge(new VenueId(3), new VenueId(3));
    }

    public function testMergeIntoAMissingTargetIsRejectedBeforeAnythingChanges(): void
    {
        $this->repository->method('findById')->willReturn(null);
        $this->repository->expects($this->never())->method('reassignEvents');
        $this->repository->expects($this->never())->method('delete');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->merge(new VenueId(1), new VenueId(2));
    }
}
