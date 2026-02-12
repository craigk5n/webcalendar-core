<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\ConfigService;
use WebCalendar\Core\Domain\Repository\ConfigRepositoryInterface;

final class ConfigServiceTest extends TestCase
{
    /** @var ConfigRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $configRepository;
    private ConfigService $configService;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepositoryInterface::class);
        $this->configService = new ConfigService($this->configRepository);
    }

    public function testGetSetting(): void
    {
        $this->configRepository->expects($this->once())
            ->method('get')
            ->with('APPLICATION_NAME')
            ->willReturn('WebCalendar');

        $this->assertSame('WebCalendar', $this->configService->getSetting('APPLICATION_NAME'));
    }

    public function testGetSettingDefault(): void
    {
        $this->configRepository->expects($this->once())
            ->method('get')
            ->with('UNKNOWN')
            ->willReturn(null);

        $this->assertSame('default', $this->configService->getSetting('UNKNOWN', 'default'));
    }

    public function testUpdateSetting(): void
    {
        $this->configRepository->expects($this->once())
            ->method('set')
            ->with('APPLICATION_NAME', 'My Cal');

        $this->configService->updateSetting('APPLICATION_NAME', 'My Cal');
    }
}
