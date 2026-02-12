<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Report;

final class ReportTest extends TestCase
{
    public function testCanBeCreatedWithValidData(): void
    {
        $report = new Report(
            id: 1,
            owner: 'jdoe',
            name: 'Monthly Summary',
            type: 'monthly',
            isGlobal: false
        );

        $this->assertSame(1, $report->id());
        $this->assertSame('jdoe', $report->owner());
        $this->assertSame('Monthly Summary', $report->name());
        $this->assertSame('monthly', $report->type());
        $this->assertFalse($report->isGlobal());
    }
}
