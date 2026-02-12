<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\View;
use WebCalendar\Core\Domain\ValueObject\ViewType;

final class ViewTest extends TestCase
{
    public function testCanBeCreatedWithValidData(): void
    {
        $view = new View(
            id: 1,
            owner: 'jdoe',
            name: 'Team View',
            type: ViewType::MONTH,
            isGlobal: false
        );

        $this->assertSame(1, $view->id());
        $this->assertSame('jdoe', $view->owner());
        $this->assertSame('Team View', $view->name());
        $this->assertSame(ViewType::MONTH, $view->type());
        $this->assertFalse($view->isGlobal());
    }
}
