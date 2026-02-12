<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\ActivityLogService;
use WebCalendar\Core\Domain\Repository\ActivityLogRepositoryInterface;
use WebCalendar\Core\Domain\Entity\ActivityLogEntry;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;
use WebCalendar\Core\Domain\ValueObject\DateRange;

final class ActivityLogServiceTest extends TestCase
{
    /** @var ActivityLogRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $activityLogRepository;
    private ActivityLogService $activityLogService;

    protected function setUp(): void
    {
        $this->activityLogRepository = $this->createMock(ActivityLogRepositoryInterface::class);
        $this->activityLogService = new ActivityLogService($this->activityLogRepository);
    }

    public function testLogActivity(): void
    {
        $this->activityLogRepository->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(ActivityLogEntry::class));

        $this->activityLogService->log(
            123,
            'admin',
            'jdoe',
            ActivityLogType::CREATE,
            'Created meeting'
        );
    }

    public function testGetLogs(): void
    {
        $range = new DateRange(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-02-28'));
        
        $this->activityLogRepository->expects($this->once())
            ->method('findByDateRange')
            ->with($range, 'admin')
            ->willReturn([]);

        $result = $this->activityLogService->getLogs($range, 'admin');
        $this->assertSame([], $result);
    }
}
