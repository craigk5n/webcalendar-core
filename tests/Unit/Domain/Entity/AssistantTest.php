<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Assistant;

final class AssistantTest extends TestCase
{
    public function testCanBeCreatedWithValidData(): void
    {
        $assistant = new Assistant(
            boss: 'boss1',
            assistant: 'asst1'
        );

        $this->assertSame('boss1', $assistant->boss());
        $this->assertSame('asst1', $assistant->assistant());
    }
}
