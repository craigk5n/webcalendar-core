<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Repository;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\DateRange;

final class EventRepositoryInterfaceTest extends TestCase
{
    public function testCanMockInterface(): void
    {
        $repository = $this->createMock(EventRepositoryInterface::class);
        
        $id = new EventId(1);
        $repository->expects($this->once())
            ->method('findById')
            ->with($id)
            ->willReturn(null);

        $this->assertNull($repository->findById($id));
    }
}
