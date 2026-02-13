<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\TemplateService;
use WebCalendar\Core\Domain\Repository\TemplateRepositoryInterface;

final class TemplateServiceTest extends TestCase
{
    /** @var TemplateRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $templateRepository;
    private TemplateService $templateService;

    protected function setUp(): void
    {
        $this->templateRepository = $this->createMock(TemplateRepositoryInterface::class);
        $this->templateService = new TemplateService($this->templateRepository);
    }

    public function testGetTemplate(): void
    {
        $login = 'jdoe';
        $type = 'H'; // Header
        
        $this->templateRepository->expects($this->once())
            ->method('get')
            ->with($login, $type)
            ->willReturn('Header Text');

        $this->assertSame('Header Text', $this->templateService->getTemplate($login, $type));
    }
}
