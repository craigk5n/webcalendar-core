<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\AssistantService;
use WebCalendar\Core\Domain\Repository\AssistantRepositoryInterface;
use WebCalendar\Core\Domain\Entity\Assistant;

final class AssistantServiceTest extends TestCase
{
    /** @var AssistantRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $assistantRepository;
    private AssistantService $assistantService;

    protected function setUp(): void
    {
        $this->assistantRepository = $this->createMock(AssistantRepositoryInterface::class);
        $this->assistantService = new AssistantService($this->assistantRepository);
    }

    public function testGetAssistantsForBoss(): void
    {
        $boss = 'boss1';
        $assistants = ['asst1', 'asst2'];
        
        $this->assistantRepository->expects($this->once())
            ->method('findAssistantsForBoss')
            ->with($boss)
            ->willReturn($assistants);

        $result = $this->assistantService->getAssistantsForBoss($boss);
        $this->assertSame($assistants, $result);
    }
}
