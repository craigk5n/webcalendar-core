<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Entity\User;
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

        $result = $this->activityLogService->getLogs($range, $this->user('admin', true), 'admin');
        $this->assertSame([], $result);
    }

    // -- who may read whose audit trail ------------------------------------
    //
    // webcal_entry_log records who did what, so another user's entries are a
    // disclosure. There is no cal_access column here; this is authorization,
    // not row scoping.

    private function user(string $login, bool $isAdmin = false): User
    {
        return new User($login, 'Test', 'User', "$login@example.com", $isAdmin, true);
    }

    private function range(): DateRange
    {
        return new DateRange(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-02-28'));
    }

    public function testAdminMayReadEveryUsersEntries(): void
    {
        $range = $this->range();

        $this->activityLogRepository->expects($this->once())
            ->method('findByDateRange')
            ->with($range, null)
            ->willReturn([]);

        $this->activityLogService->getLogs($range, $this->user('admin', true));
    }

    public function testAdminMayReadAnotherUsersEntries(): void
    {
        $range = $this->range();

        $this->activityLogRepository->expects($this->once())
            ->method('findByDateRange')
            ->with($range, 'jdoe')
            ->willReturn([]);

        $this->activityLogService->getLogs($range, $this->user('admin', true), 'jdoe');
    }

    /**
     * The leak this closes: a bare getLogs() used to read the whole site's
     * audit trail for whoever called it.
     */
    public function testABareRequestFromANonAdminReadsOnlyTheirOwnEntries(): void
    {
        $range = $this->range();

        $this->activityLogRepository->expects($this->once())
            ->method('findByDateRange')
            ->with($range, 'jdoe')
            ->willReturn([]);

        $this->activityLogService->getLogs($range, $this->user('jdoe'));
    }

    public function testANonAdminMayAskForTheirOwnEntriesExplicitly(): void
    {
        $range = $this->range();

        $this->activityLogRepository->expects($this->once())
            ->method('findByDateRange')
            ->with($range, 'jdoe')
            ->willReturn([]);

        $this->activityLogService->getLogs($range, $this->user('jdoe'), 'jdoe');
    }

    public function testANonAdminMayNotReadAnotherUsersEntries(): void
    {
        $this->activityLogRepository->expects($this->never())->method('findByDateRange');

        $this->expectException(AuthorizationException::class);
        $this->activityLogService->getLogs($this->range(), $this->user('jdoe'), 'bsmith');
    }
}
