<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\CalendarAccess;
use WebCalendar\Core\Domain\ValueObject\CalendarPermission;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class CalendarAccessTest extends TestCase
{
    public function testFullGrantAllowsEverything(): void
    {
        $grant = CalendarAccess::full('jdoe', 'bsmith');

        $this->assertTrue($grant->canView(EventType::JOURNAL, AccessLevel::PRIVATE));
        $this->assertTrue($grant->canEdit(EventType::TASK, AccessLevel::CONFIDENTIAL));
        $this->assertTrue($grant->canApprove(EventType::EVENT, AccessLevel::PUBLIC));
        $this->assertTrue($grant->canInvite());
        $this->assertTrue($grant->canEmail());
        $this->assertFalse($grant->seeTimeOnly());
    }

    public function testNoneGrantAllowsNothing(): void
    {
        $grant = CalendarAccess::none('jdoe', 'bsmith');

        $this->assertFalse($grant->canView(EventType::EVENT, AccessLevel::PUBLIC));
        $this->assertFalse($grant->canEdit(EventType::EVENT, AccessLevel::PUBLIC));
        $this->assertFalse($grant->canApprove(EventType::EVENT, AccessLevel::PUBLIC));
        $this->assertFalse($grant->canInvite());
        $this->assertFalse($grant->canEmail());
    }

    /**
     * The three columns are independent: being able to see an entry says
     * nothing about being able to change it.
     */
    public function testViewEditAndApproveAreIndependent(): void
    {
        $grant = new CalendarAccess(
            'jdoe',
            'bsmith',
            new CalendarPermission(CalendarPermission::ALL),
            CalendarPermission::none(),
            CalendarPermission::none()
        );

        $this->assertTrue($grant->canView(EventType::EVENT, AccessLevel::PRIVATE));
        $this->assertFalse($grant->canEdit(EventType::EVENT, AccessLevel::PRIVATE));
        $this->assertFalse($grant->canApprove(EventType::EVENT, AccessLevel::PRIVATE));
    }

    public function testRejectsEmptyLogins(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CalendarAccess::full('', 'bsmith');
    }

    public function testRejectsEmptyOwner(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CalendarAccess::full('jdoe', '   ');
    }

    public function testRecognisesWildcardGrants(): void
    {
        $this->assertTrue(CalendarAccess::full(CalendarAccess::DEFAULT_LOGIN, 'bsmith')->isDefaultGrant());
        $this->assertTrue(CalendarAccess::full('jdoe', CalendarAccess::DEFAULT_LOGIN)->isDefaultGrant());
        $this->assertFalse(CalendarAccess::full('jdoe', 'bsmith')->isDefaultGrant());
    }
}
