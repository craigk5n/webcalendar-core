<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\SiteExtraService;
use WebCalendar\Core\Domain\Repository\SiteExtraRepositoryInterface;

final class SiteExtraServiceTest extends TestCase
{
    /** @var SiteExtraRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $siteExtraRepository;
    private SiteExtraService $siteExtraService;

    protected function setUp(): void
    {
        $this->siteExtraRepository = $this->createMock(SiteExtraRepositoryInterface::class);
        $this->siteExtraService = new SiteExtraService($this->siteExtraRepository);
    }

    public function testGetExtrasForEvent(): void
    {
        $eventId = 123;
        $extras = ['FIELD1' => 'Value 1'];
        
        $this->siteExtraRepository->expects($this->once())
            ->method('getForEvent')
            ->with($eventId)
            ->willReturn($extras);

        $this->assertSame($extras, $this->siteExtraService->getExtrasForEvent($eventId));
    }
}
